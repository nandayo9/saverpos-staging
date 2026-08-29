<?php

namespace Modules\Recommerce\Services;

use App\Transaction;
use App\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;
use Modules\Recommerce\Entities\CustodyPeriod;
use Modules\Recommerce\Entities\Device;
use Modules\Recommerce\Entities\DeviceMovement;
use Modules\Recommerce\Entities\DeviceReturnDisposition;
use Modules\Recommerce\Entities\DeviceSaleDisposition;
use Modules\Recommerce\Entities\DeviceTransferAssignment;
use Modules\Recommerce\Entities\DeviceTransferException;
use Modules\Recommerce\Entities\OwnershipPeriod;
use Modules\Recommerce\Entities\SerializationProfile;
use Modules\Recommerce\Support\AuthorizationGate;

/**
 * Physical-device projection of already-authoritative Ultimate POS rows.
 * Every public method is called from an existing core transaction; it never
 * creates inventory, sale, payment, accounting, or warranty records.
 */
class DeviceLifecycleService
{
    public function __construct(
        protected AuthorizationGate $authorizationGate,
        protected DeviceEventRecorder $eventRecorder
    ) {
    }

    public function synchroniseFinalSale(User $user, Transaction $sale, array $requestedProducts): void
    {
        if (! config('recommerce.enabled') || ! config('recommerce.writes_enabled')) {
            return;
        }
        if ($sale->type !== 'sell' || $sale->status !== 'final') {
            $this->reverseSale($user, $sale, 'SALE_NO_LONGER_FINAL');
            return;
        }

        $expected = $this->selectedDevicesForLines($sale, $requestedProducts, 'recommerce_device_codes');
        if ($expected === []) {
            return;
        }

        $this->assertOperationScope($user, 'recommerce.device.sell', $sale, $expected);
        $active = DeviceSaleDisposition::query()
            ->where('sale_transaction_id', $sale->id)
            ->whereNotNull('active_sale_key')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $current = $active->map(fn ($row) => $row->sell_line_id.':'.$row->device_id)->sort()->values()->all();
        $wanted = collect($expected)->map(fn ($row) => $row['sell_line_id'].':'.$row['device_id'])->sort()->values()->all();
        if ($current === $wanted) {
            return;
        }

        foreach ($active as $disposition) {
            $this->reverseSaleDisposition($user, $sale, $disposition, 'SALE_LINE_REPLACED');
        }

        foreach ($expected as $selection) {
            $device = Device::query()->whereKey($selection['device_id'])->lockForUpdate()->firstOrFail();
            $this->assertSellable($device, $sale, $selection);
            $this->closeOpenPeriods($device, $user->id);
            $disposition = DeviceSaleDisposition::create([
                'device_id' => $device->id,
                'business_id' => $sale->business_id,
                'sale_transaction_id' => $sale->id,
                'sell_line_id' => $selection['sell_line_id'],
                'customer_contact_id' => $sale->contact_id,
                'sold_at' => now(),
                'active_sale_key' => $device->id,
                'recorded_by' => $user->id,
            ]);
            $this->move($device, 'SALE', $sale->location_id, null, 'LOCATION', 'CUSTOMER', $sale->id, $selection['sell_line_id'], $user->id);
            $device->update([
                'ownership_kind' => 'CUSTOMER', 'current_owner_contact_id' => $sale->contact_id,
                'custody_kind' => 'CUSTOMER', 'current_location_id' => null,
                'lifecycle_state' => 'SOLD', 'stock_participation' => 'NONE',
                'sold_at' => now(), 'updated_by' => $user->id, 'lock_version' => $device->lock_version + 1,
            ]);
            OwnershipPeriod::create(['device_id' => $device->id, 'business_id' => $device->business_id, 'owner_kind' => 'CUSTOMER', 'contact_id' => $sale->contact_id, 'starts_at' => now(), 'open_period_key' => $device->id, 'sale_transaction_id' => $sale->id, 'reason' => 'SALE', 'recorded_by' => $user->id]);
            CustodyPeriod::create(['device_id' => $device->id, 'business_id' => $device->business_id, 'custody_kind' => 'CUSTOMER', 'starts_at' => now(), 'open_period_key' => $device->id, 'reason' => 'SALE', 'recorded_by' => $user->id]);
            $this->eventRecorder->recordLifecycle($device->fresh(), 'SALE_DISPOSED', $user->id, $sale->id, ['sell_line_id' => $selection['sell_line_id'], 'disposition_id' => $disposition->id]);
        }
    }

    public function reverseSale(User $user, Transaction $sale, string $reason = 'SALE_REVERSED'): void
    {
        $active = DeviceSaleDisposition::query()->where('sale_transaction_id', $sale->id)->whereNotNull('active_sale_key')->lockForUpdate()->get();
        foreach ($active as $disposition) {
            $this->reverseSaleDisposition($user, $sale, $disposition, $reason);
        }
    }

    public function recordReturn(User $user, Transaction $return, array $requestedProducts): void
    {
        if (! config('recommerce.enabled') || ! config('recommerce.writes_enabled')) {
            return;
        }
        if ($return->type !== 'sell_return' || $return->status !== 'final') {
            return;
        }
        // Ultimate POS records returned quantities against the original sell
        // lines; it does not create transaction sell lines on sell_return.
        // Resolve exact-device selections against that authoritative parent.
        $selectionTransaction = $return;
        if ($return->return_parent_id && ! $return->sell_lines()->exists()) {
            $selectionTransaction = Transaction::query()->findOrFail($return->return_parent_id);
        }
        $expected = $this->selectedDevicesForLines($selectionTransaction, $requestedProducts, 'recommerce_device_codes', 'quantity');
        foreach ($expected as $selection) {
            $device = Device::query()->whereKey($selection['device_id'])->lockForUpdate()->firstOrFail();
            $this->assertReturnScope($user, $return, $device);
            $saleDisposition = DeviceSaleDisposition::query()->where('device_id', $device->id)->where('sale_transaction_id', $return->return_parent_id)->whereNotNull('active_sale_key')->lockForUpdate()->first();
            if (! $saleDisposition) {
                throw new LogicException('Returned device is not actively attributed to the original sale.');
            }
            if (DeviceReturnDisposition::query()->where('return_transaction_id', $return->id)->where('device_id', $device->id)->exists()) {
                continue;
            }
            $saleDisposition->update(['active_sale_key' => null]);
            $this->closeOpenPeriods($device, $user->id);
            $state = $selection['return_state'] ?? 'RETURNED_PENDING_INSPECTION';
            $movement = $this->move($device, 'SALE_RETURN', null, $return->location_id, 'CUSTOMER', 'LOCATION', $return->id, $selection['sell_line_id'], $user->id);
            // Core sell-return has restored aggregate quantity. The returned
            // device therefore participates in physical reconciliation even
            // while its lifecycle blocks resale pending inspection.
            $device->update(['ownership_kind' => 'BUSINESS', 'current_owner_contact_id' => null, 'custody_kind' => 'LOCATION', 'current_location_id' => $return->location_id, 'lifecycle_state' => $state, 'stock_participation' => 'ON_HAND', 'updated_by' => $user->id, 'lock_version' => $device->lock_version + 1]);
            OwnershipPeriod::create(['device_id' => $device->id, 'business_id' => $device->business_id, 'owner_kind' => 'BUSINESS', 'starts_at' => now(), 'open_period_key' => $device->id, 'reason' => 'SALE_RETURN', 'recorded_by' => $user->id]);
            CustodyPeriod::create(['device_id' => $device->id, 'business_id' => $device->business_id, 'custody_kind' => 'LOCATION', 'location_id' => $return->location_id, 'starts_at' => now(), 'open_period_key' => $device->id, 'source_movement_id' => $movement->id, 'reason' => 'SALE_RETURN', 'recorded_by' => $user->id]);
            $record = DeviceReturnDisposition::create(['device_id' => $device->id, 'sale_disposition_id' => $saleDisposition->id, 'business_id' => $device->business_id, 'return_transaction_id' => $return->id, 'return_line_id' => $selection['sell_line_id'], 'resulting_lifecycle_state' => $state, 'returned_at' => now(), 'active_return_key' => $device->id, 'recorded_by' => $user->id]);
            $this->eventRecorder->recordLifecycle($device->fresh(), 'SALE_RETURN_RECORDED', $user->id, $return->id, ['return_line_id' => $selection['sell_line_id'], 'return_disposition_id' => $record->id]);
        }
    }

    public function recordCompletedTransfer(User $user, Transaction $sellTransfer, Transaction $purchaseTransfer, array $requestedProducts): void
    {
        if (! config('recommerce.enabled') || ! config('recommerce.writes_enabled')) {
            return;
        }
        if ($sellTransfer->type !== 'sell_transfer' || $sellTransfer->status !== 'final') {
            return;
        }
        if (DeviceTransferException::query()
            ->where('sell_transfer_transaction_id', $sellTransfer->id)
            ->where('status', 'OPEN')
            ->exists()) {
            throw new LogicException('Tracked transfer has unresolved receiving exceptions.');
        }
        $expected = $requestedProducts === []
            ? $this->reservedTransferSelections($sellTransfer)
            : $this->selectedDevicesForLines($sellTransfer, $requestedProducts, 'recommerce_device_codes');
        $this->assertOperationScope($user, 'recommerce.device.transfer', $sellTransfer, $expected);
        $active = DeviceTransferAssignment::query()
            ->where('sell_transfer_transaction_id', $sellTransfer->id)
            ->where('status', 'RESERVED')
            ->whereNotNull('active_transfer_key')
            ->lockForUpdate()
            ->get();
        $expectedDeviceIds = collect($expected)->pluck('device_id')->sort()->values()->all();
        $reservedDeviceIds = $active->pluck('device_id')->sort()->values()->all();
        if ($expectedDeviceIds !== $reservedDeviceIds) {
            throw new LogicException('Tracked transfer completion must use its originally reserved devices.');
        }
        foreach ($expected as $selection) {
            $device = Device::query()->whereKey($selection['device_id'])->lockForUpdate()->firstOrFail();
            $assignment = $active->firstWhere('device_id', $device->id);
            if (! $assignment || $device->stock_participation !== 'IN_TRANSFER' || $device->lifecycle_state !== 'TRANSFER_PENDING') {
                throw new LogicException('Tracked device is not reserved for this transfer.');
            }
            $movement = $this->move($device, 'TRANSFER', $sellTransfer->location_id, $purchaseTransfer->location_id, 'LOCATION', 'LOCATION', $sellTransfer->id, $selection['sell_line_id'], $user->id);
            $device->update(['current_location_id' => $purchaseTransfer->location_id, 'custody_kind' => 'LOCATION', 'lifecycle_state' => 'AVAILABLE', 'stock_participation' => 'ON_HAND', 'updated_by' => $user->id, 'lock_version' => $device->lock_version + 1]);
            $this->closeOpenCustody($device, $user->id);
            CustodyPeriod::create(['device_id' => $device->id, 'business_id' => $device->business_id, 'custody_kind' => 'LOCATION', 'location_id' => $purchaseTransfer->location_id, 'starts_at' => now(), 'open_period_key' => $device->id, 'source_movement_id' => $movement->id, 'reason' => 'TRANSFER', 'recorded_by' => $user->id]);
            $assignment->update(['status' => 'COMPLETED', 'active_transfer_key' => null, 'transferred_at' => now()]);
            $this->eventRecorder->recordLifecycle($device->fresh(), 'TRANSFER_COMPLETED', $user->id, $sellTransfer->id, ['transfer_assignment_id' => $assignment->id, 'to_location_id' => $purchaseTransfer->location_id]);
        }
    }

    /** Reserves exact Devices while the native transfer is pending/in transit. */
    public function synchroniseTransferReservation(User $user, Transaction $sellTransfer, Transaction $purchaseTransfer, array $requestedProducts): void
    {
        if (! config('recommerce.enabled') || ! config('recommerce.writes_enabled')) {
            return;
        }
        // `final` is included only for the immediate-complete path: the core
        // controller persists its final status before the enclosing database
        // transaction reaches the Recommerce reservation/completion pair.
        if ($sellTransfer->type !== 'sell_transfer' || ! in_array($sellTransfer->status, ['pending', 'in_transit', 'final'], true)) {
            return;
        }
        $expected = $this->selectedDevicesForLines($sellTransfer, $requestedProducts, 'recommerce_device_codes');
        $this->assertOperationScope($user, 'recommerce.device.transfer', $sellTransfer, $expected);
        $active = DeviceTransferAssignment::query()
            ->where('sell_transfer_transaction_id', $sellTransfer->id)
            ->where('status', 'RESERVED')
            ->whereNotNull('active_transfer_key')
            ->lockForUpdate()
            ->get();
        $current = $active->map(fn ($row) => $row->sell_line_id.':'.$row->device_id)->sort()->values()->all();
        $wanted = collect($expected)->map(fn ($row) => $row['sell_line_id'].':'.$row['device_id'])->sort()->values()->all();
        if ($current === $wanted) {
            return;
        }
        foreach ($active as $assignment) {
            $this->releaseTransferReservation($user, $sellTransfer, $assignment, 'TRANSFER_SELECTION_CHANGED');
        }
        foreach ($expected as $selection) {
            $device = Device::query()->whereKey($selection['device_id'])->lockForUpdate()->firstOrFail();
            $this->assertTransferScope($user, $sellTransfer, $device, $selection['variation_id']);
            $assignment = DeviceTransferAssignment::create([
                'device_id' => $device->id, 'business_id' => $device->business_id,
                'sell_transfer_transaction_id' => $sellTransfer->id,
                'purchase_transfer_transaction_id' => $purchaseTransfer->id,
                'sell_line_id' => $selection['sell_line_id'],
                'from_location_id' => $sellTransfer->location_id,
                'to_location_id' => $purchaseTransfer->location_id,
                'transferred_at' => now(), 'status' => 'RESERVED',
                'active_transfer_key' => $device->id, 'recorded_by' => $user->id,
            ]);
            $device->update(['lifecycle_state' => 'TRANSFER_PENDING', 'stock_participation' => 'IN_TRANSFER', 'updated_by' => $user->id, 'lock_version' => $device->lock_version + 1]);
            $this->eventRecorder->recordLifecycle($device->fresh(), 'TRANSFER_RESERVED', $user->id, $sellTransfer->id, ['transfer_assignment_id' => $assignment->id, 'to_location_id' => $purchaseTransfer->location_id]);
        }
    }

    /** Cancels an uncompleted transfer while retaining the native record and audit trail. */
    public function cancelTransfer(User $user, Transaction $sellTransfer): void
    {
        if (! config('recommerce.enabled') || ! config('recommerce.writes_enabled')) {
            return;
        }
        $assignments = DeviceTransferAssignment::query()
            ->where('sell_transfer_transaction_id', $sellTransfer->id)
            ->where('status', 'RESERVED')
            ->whereNotNull('active_transfer_key')
            ->lockForUpdate()
            ->get();
        foreach ($assignments as $assignment) {
            $this->releaseTransferReservation($user, $sellTransfer, $assignment, 'TRANSFER_CANCELLED');
        }
    }

    /** Completed transfer reversal restores source custody without deleting history. */
    public function reverseCompletedTransfer(User $user, Transaction $sellTransfer): void
    {
        if (! config('recommerce.enabled') || ! config('recommerce.writes_enabled')) {
            return;
        }
        $assignments = DeviceTransferAssignment::query()
            ->where('sell_transfer_transaction_id', $sellTransfer->id)
            ->where('status', 'COMPLETED')
            ->whereNull('reversed_at')
            ->lockForUpdate()
            ->get();
        foreach ($assignments as $assignment) {
            $device = Device::query()->whereKey($assignment->device_id)->lockForUpdate()->firstOrFail();
            if ((int) $device->current_location_id !== (int) $assignment->to_location_id || $device->lifecycle_state !== 'AVAILABLE' || $device->stock_participation !== 'ON_HAND') {
                throw new LogicException('Completed transfer cannot be reversed after the device has changed state.');
            }
            $this->assertOperationScope($user, 'recommerce.device.reverse_disposition', $sellTransfer, [['variation_id' => $device->variation_id]]);
            $movement = $this->move($device, 'TRANSFER_REVERSAL', $assignment->to_location_id, $assignment->from_location_id, 'LOCATION', 'LOCATION', $sellTransfer->id, $assignment->sell_line_id, $user->id);
            $this->closeOpenCustody($device, $user->id);
            $device->update(['current_location_id' => $assignment->from_location_id, 'updated_by' => $user->id, 'lock_version' => $device->lock_version + 1]);
            CustodyPeriod::create(['device_id' => $device->id, 'business_id' => $device->business_id, 'custody_kind' => 'LOCATION', 'location_id' => $assignment->from_location_id, 'starts_at' => now(), 'open_period_key' => $device->id, 'source_movement_id' => $movement->id, 'reason' => 'TRANSFER_REVERSAL', 'recorded_by' => $user->id]);
            $assignment->update(['status' => 'REVERSED', 'reversed_at' => now(), 'reversal_transaction_id' => $sellTransfer->id]);
            $this->eventRecorder->recordLifecycle($device->fresh(), 'TRANSFER_REVERSED', $user->id, $sellTransfer->id, ['transfer_assignment_id' => $assignment->id]);
        }
    }

    /** Restores the original active sale when a core sell return is deleted. */
    public function reverseReturn(User $user, Transaction $return): void
    {
        if (! config('recommerce.enabled') || ! config('recommerce.writes_enabled')) {
            return;
        }
        $returns = DeviceReturnDisposition::query()
            ->where('return_transaction_id', $return->id)
            ->whereNotNull('active_return_key')
            ->lockForUpdate()
            ->get();
        foreach ($returns as $returnDisposition) {
            $device = Device::query()->whereKey($returnDisposition->device_id)->lockForUpdate()->firstOrFail();
            $saleDisposition = DeviceSaleDisposition::query()->whereKey($returnDisposition->sale_disposition_id)->lockForUpdate()->firstOrFail();
            $sale = Transaction::query()->whereKey($saleDisposition->sale_transaction_id)->lockForUpdate()->firstOrFail();
            $this->assertOperationScope($user, 'recommerce.device.reverse_disposition', $sale, [[
                'variation_id' => $device->variation_id,
            ]]);
            $returnDisposition->update(['active_return_key' => null, 'reversed_at' => now()]);
            $saleDisposition->update(['active_sale_key' => $device->id]);
            $this->closeOpenPeriods($device, $user->id);
            $this->move($device, 'SALE_RETURN_REVERSAL', $return->location_id, null, 'LOCATION', 'CUSTOMER', $return->id, $returnDisposition->return_line_id, $user->id);
            $device->update(['ownership_kind' => 'CUSTOMER', 'current_owner_contact_id' => $sale->contact_id, 'custody_kind' => 'CUSTOMER', 'current_location_id' => null, 'lifecycle_state' => 'SOLD', 'stock_participation' => 'NONE', 'sold_at' => $saleDisposition->sold_at, 'updated_by' => $user->id, 'lock_version' => $device->lock_version + 1]);
            OwnershipPeriod::create(['device_id' => $device->id, 'business_id' => $device->business_id, 'owner_kind' => 'CUSTOMER', 'contact_id' => $sale->contact_id, 'starts_at' => now(), 'open_period_key' => $device->id, 'sale_transaction_id' => $sale->id, 'reason' => 'SALE_RETURN_REVERSAL', 'recorded_by' => $user->id]);
            CustodyPeriod::create(['device_id' => $device->id, 'business_id' => $device->business_id, 'custody_kind' => 'CUSTOMER', 'starts_at' => now(), 'open_period_key' => $device->id, 'reason' => 'SALE_RETURN_REVERSAL', 'recorded_by' => $user->id]);
            $this->eventRecorder->recordLifecycle($device->fresh(), 'SALE_RETURN_REVERSED', $user->id, $return->id, ['return_disposition_id' => $returnDisposition->id]);
        }
    }

    protected function selectedDevicesForLines(Transaction $transaction, array $requestedProducts, string $field, ?string $quantityField = null): array
    {
        $lines = $transaction->sell_lines()->orderBy('id')->get()->groupBy(fn ($line) => $line->product_id.':'.$line->variation_id);
        $inputs = collect($requestedProducts)->groupBy(fn ($input) => ($input['product_id'] ?? '').':'.($input['variation_id'] ?? ''));
        $selected = [];
        foreach ($lines as $key => $coreLines) {
            $tracked = $this->isTracked($transaction->business_id, (int) $coreLines->first()->variation_id);
            if (! $tracked) { continue; }
            $inputRows = $inputs->get($key, collect())->values();
            if ($coreLines->count() !== $inputRows->count()) { throw new InvalidArgumentException('Tracked sale line selection is incomplete.'); }
            foreach ($coreLines->values() as $index => $line) {
                $codes = $this->codes($inputRows[$index][$field] ?? []);
                $quantity = $quantityField !== null && array_key_exists($quantityField, $inputRows[$index] ?? [])
                    ? (float) $inputRows[$index][$quantityField]
                    : (float) $line->quantity;
                if ($quantity > (float) $line->quantity) { throw new InvalidArgumentException('Tracked quantity cannot exceed the original line quantity.'); }
                if ($quantity !== floor($quantity) || count($codes) !== (int) $quantity) { throw new InvalidArgumentException('Tracked quantity must equal the number of selected devices.'); }
                foreach ($codes as $code) {
                    $device = Device::query()->where('business_id', $transaction->business_id)->where('device_code', $code)->first();
                    if (! $device) { throw new InvalidArgumentException('Selected tracked device was not found.'); }
                    $selected[] = ['device_id' => $device->id, 'sell_line_id' => $line->id, 'variation_id' => $line->variation_id, 'return_state' => $inputRows[$index]['recommerce_return_state'] ?? null];
                }
            }
        }
        if (count($selected) !== count(array_unique(array_column($selected, 'device_id')))) { throw new InvalidArgumentException('A tracked device may only be selected once.'); }
        return $selected;
    }

    protected function reservedTransferSelections(Transaction $transfer): array
    {
        $assignments = DeviceTransferAssignment::query()
            ->where('sell_transfer_transaction_id', $transfer->id)
            ->where('status', 'RESERVED')
            ->whereNotNull('active_transfer_key')
            ->get();
        $trackedLines = $transfer->sell_lines()->orderBy('id')->get()->filter(fn ($line) => $this->isTracked($transfer->business_id, (int) $line->variation_id));
        foreach ($trackedLines as $line) {
            $count = $assignments->where('sell_line_id', $line->id)->count();
            if ((float) $line->quantity !== floor((float) $line->quantity) || $count !== (int) $line->quantity) {
                throw new LogicException('Tracked transfer has incomplete reserved device evidence.');
            }
        }
        return $assignments->map(function (DeviceTransferAssignment $assignment): array {
            $device = Device::query()->findOrFail($assignment->device_id);
            return ['device_id' => $assignment->device_id, 'sell_line_id' => $assignment->sell_line_id, 'variation_id' => $device->variation_id];
        })->all();
    }

    protected function isTracked(int $businessId, int $variationId): bool
    {
        return SerializationProfile::query()->where('business_id', $businessId)->where('variation_id', $variationId)->where('mode', 'TRACKED_REQUIRED')->whereNotNull('configured_by')->whereNotNull('approval_reference')->exists();
    }

    protected function codes($value): array
    {
        $values = is_array($value) ? $value : preg_split('/[\s,]+/', (string) $value, -1, PREG_SPLIT_NO_EMPTY);
        return array_values(array_unique(array_map(fn ($code) => strtoupper(trim((string) $code)), $values)));
    }

    protected function assertOperationScope(User $user, string $permission, Transaction $transaction, array $selections): void
    {
        foreach ($selections as $selection) {
            if (! $this->authorizationGate->allowsWrite($user, $permission, $transaction->business_id, $transaction->location_id, $selection['variation_id'])) {
                throw new AuthorizationException('Recommerce lifecycle scope denied.');
            }
        }
    }

    protected function assertSellable(Device $device, Transaction $sale, array $selection): void
    {
        if ((int) $device->business_id !== (int) $sale->business_id || (int) $device->variation_id !== (int) $selection['variation_id'] || (int) $device->current_location_id !== (int) $sale->location_id || $device->lifecycle_state !== 'AVAILABLE' || $device->stock_participation !== 'ON_HAND') { throw new LogicException('Selected device is not available at the selling branch.'); }
    }

    protected function assertReturnScope(User $user, Transaction $return, Device $device): void
    {
        if (! $this->authorizationGate->allowsWrite($user, 'recommerce.device.return', $return->business_id, $return->location_id, $device->variation_id)) { throw new AuthorizationException('Recommerce return scope denied.'); }
    }

    protected function assertTransferScope(User $user, Transaction $transfer, Device $device, ?int $expectedVariationId = null): void
    {
        if (! $this->authorizationGate->allowsWrite($user, 'recommerce.device.transfer', $transfer->business_id, $transfer->location_id, $device->variation_id) || ($expectedVariationId !== null && (int) $device->variation_id !== $expectedVariationId) || (int) $device->current_location_id !== (int) $transfer->location_id || $device->lifecycle_state !== 'AVAILABLE' || $device->stock_participation !== 'ON_HAND') { throw new AuthorizationException('Recommerce transfer scope denied.'); }
    }

    protected function releaseTransferReservation(User $user, Transaction $sellTransfer, DeviceTransferAssignment $assignment, string $reason): void
    {
        $device = Device::query()->whereKey($assignment->device_id)->lockForUpdate()->firstOrFail();
        if ((int) $device->current_location_id !== (int) $assignment->from_location_id || $device->stock_participation !== 'IN_TRANSFER') {
            throw new LogicException('Transfer reservation cannot be released after physical state changed.');
        }
        $this->assertOperationScope($user, 'recommerce.device.reverse_disposition', $sellTransfer, [['variation_id' => $device->variation_id]]);
        $assignment->update(['status' => $reason === 'TRANSFER_CANCELLED' ? 'CANCELLED' : 'REPLACED', 'active_transfer_key' => null, 'reversed_at' => now(), 'reversal_transaction_id' => $sellTransfer->id]);
        $device->update(['lifecycle_state' => 'AVAILABLE', 'stock_participation' => 'ON_HAND', 'updated_by' => $user->id, 'lock_version' => $device->lock_version + 1]);
        $this->eventRecorder->recordLifecycle($device->fresh(), $reason, $user->id, $sellTransfer->id, ['transfer_assignment_id' => $assignment->id]);
    }

    protected function reverseSaleDisposition(User $user, Transaction $sale, DeviceSaleDisposition $disposition, string $reason): void
    {
        $device = Device::query()->whereKey($disposition->device_id)->lockForUpdate()->firstOrFail();
        $disposition->update(['active_sale_key' => null, 'reversed_at' => now(), 'reversal_transaction_id' => $sale->id, 'reason' => $reason]);
        $this->closeOpenPeriods($device, $user->id);
        $movement = $this->move($device, 'SALE_REVERSAL', null, $sale->location_id, 'CUSTOMER', 'LOCATION', $sale->id, $disposition->sell_line_id, $user->id);
        $device->update(['ownership_kind' => 'BUSINESS', 'current_owner_contact_id' => null, 'custody_kind' => 'LOCATION', 'current_location_id' => $sale->location_id, 'lifecycle_state' => 'AVAILABLE', 'stock_participation' => 'ON_HAND', 'sold_at' => null, 'updated_by' => $user->id, 'lock_version' => $device->lock_version + 1]);
        OwnershipPeriod::create(['device_id' => $device->id, 'business_id' => $device->business_id, 'owner_kind' => 'BUSINESS', 'starts_at' => now(), 'open_period_key' => $device->id, 'reason' => 'SALE_REVERSAL', 'recorded_by' => $user->id]);
        CustodyPeriod::create(['device_id' => $device->id, 'business_id' => $device->business_id, 'custody_kind' => 'LOCATION', 'location_id' => $sale->location_id, 'starts_at' => now(), 'open_period_key' => $device->id, 'source_movement_id' => $movement->id, 'reason' => 'SALE_REVERSAL', 'recorded_by' => $user->id]);
        $this->eventRecorder->recordLifecycle($device->fresh(), 'SALE_REVERSED', $user->id, $sale->id, ['sell_line_id' => $disposition->sell_line_id, 'sale_disposition_id' => $disposition->id]);
    }

    protected function closeOpenPeriods(Device $device, int $userId): void
    {
        OwnershipPeriod::query()->where('device_id', $device->id)->whereNotNull('open_period_key')->update(['open_period_key' => null, 'ends_at' => now(), 'recorded_by' => $userId]);
        $this->closeOpenCustody($device, $userId);
    }

    protected function closeOpenCustody(Device $device, int $userId): void
    {
        CustodyPeriod::query()->where('device_id', $device->id)->whereNotNull('open_period_key')->update(['open_period_key' => null, 'ends_at' => now(), 'recorded_by' => $userId]);
    }

    protected function move(Device $device, string $type, ?int $fromLocation, ?int $toLocation, string $fromCustody, string $toCustody, int $transactionId, ?int $lineId, int $actorId): DeviceMovement
    {
        return DeviceMovement::create(['device_id' => $device->id, 'business_id' => $device->business_id, 'movement_type' => $type, 'from_custody_kind' => $fromCustody, 'from_location_id' => $fromLocation, 'to_custody_kind' => $toCustody, 'to_location_id' => $toLocation, 'source_transaction_id' => $transactionId, 'source_line_id' => $lineId, 'source_line_type' => 'transaction_sell_line', 'occurred_at' => now(), 'recorded_by' => $actorId]);
    }
}
