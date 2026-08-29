<?php

namespace Modules\Recommerce\Services;

use App\BusinessLocation;
use App\Contact;
use App\Product;
use App\TaxRate;
use App\User;
use App\Variation;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Modules\Recommerce\Entities\Device;
use Modules\Recommerce\Entities\DeviceIdentifier;
use Modules\Recommerce\Entities\DeviceMovement;
use Modules\Recommerce\Entities\CustodyPeriod;
use Modules\Recommerce\Entities\OwnershipPeriod;
use Modules\Recommerce\Entities\DevicePurchaseAssignment;
use Modules\Recommerce\Entities\StockCommand;
use Modules\Recommerce\Exceptions\ReceivingInProgressException;
use Modules\Recommerce\Services\StockReconciliationService;
use Modules\Recommerce\Support\AuthorizationGate;
use Modules\Recommerce\Support\Identity\DeviceCode;
use Modules\Recommerce\Support\Identity\StrongIdentifierHasher;

/**
 * Atomic Alpha receiving contract. It is reachable only through the guarded
 * receiving-post route. The callback must perform the reviewed Ultimate POS
 * purchase write inside this transaction and return its persisted
 * transaction/line identifiers.
 */
class TrackedReceivingService
{
    public function __construct(
        protected AuthorizationGate $authorizationGate,
        protected ?UltimatePosPurchaseWriter $ultimatePosPurchaseWriter = null,
        protected ?DeviceEventRecorder $deviceEventRecorder = null,
        protected ?StockReconciliationService $stockReconciliationService = null
    )
    {
    }

    /**
     * Production integration seam for the reviewed Ultimate POS purchase
     * adapter. It remains inaccessible while Recommerce or its write switch is
     * disabled and requires the approved permission and cohort configuration.
     */
    public function executeWithUltimatePosPurchase(User $user, array $command): array
    {
        if (! $this->ultimatePosPurchaseWriter) {
            throw new LogicException('Ultimate POS purchase writer is not available.');
        }

        return $this->execute(
            $user,
            $command,
            fn (array $normalized) => $this->ultimatePosPurchaseWriter->write($user, $normalized)
        );
    }

    /**
     * Add physical-device evidence to a received Ultimate POS purchase line.
     *
     * The POS purchase is already the stock, supplier, price, payment, and
     * accounting record. This command deliberately creates no core purchase,
     * stock, payment, or accounting row; it only assigns one Device to every
     * unit on an unassigned received purchase line.
     */
    public function attachToExistingUltimatePosPurchase(User $user, array $command): array
    {
        $normalized = $this->normalizeExistingPurchaseAttachment($command);

        if ((string) $user->business_id !== (string) $normalized['business_id']) {
            throw new AuthorizationException('Recommerce business scope denied.');
        }

        if (! $this->authorizationGate->allowsWrite(
            $user,
            'recommerce.receiving.post',
            $normalized['business_id'],
            $normalized['location_id'],
            $normalized['variation_id']
        )) {
            throw new AuthorizationException('Recommerce receiving scope denied.');
        }

        $this->assertExistingPurchaseScope($user, $normalized);

        try {
            $requestHash = hash('sha256', json_encode(
                $normalized,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            ));
        } catch (\JsonException $exception) {
            throw new LogicException('Receiving command contains unsupported text encoding.', 0, $exception);
        }

        return DB::transaction(function () use ($user, $normalized, $requestHash) {
            DB::table('business')
                ->where('id', $normalized['business_id'])
                ->lockForUpdate()
                ->first();

            $existing = StockCommand::query()
                ->where('business_id', $normalized['business_id'])
                ->where('command_uuid', $normalized['command_uuid'])
                ->lockForUpdate()
                ->first();

            if ($existing) {
                if (! hash_equals($existing->request_hash, $requestHash)) {
                    throw new LogicException('Idempotency key was reused for a different request.');
                }

                if ($existing->status === 'COMPLETED') {
                    return $existing->result_json ?: [];
                }

                throw new ReceivingInProgressException('An equivalent receiving operation is already in progress.');
            }

            $coreReceipt = $this->lockExistingPurchaseLine($normalized, count($normalized['units']));
            $this->assertNoExistingIdentifiers($normalized);

            $commandReceipt = StockCommand::create([
                'business_id' => $normalized['business_id'],
                'command_uuid' => $normalized['command_uuid'],
                'command_type' => 'TRACKED_PURCHASE_ATTACH',
                'request_hash' => $requestHash,
                'actor_id' => $user->id,
                'status' => 'STARTED',
                'started_at' => now(),
            ]);

            $deviceResults = $this->createTrackedDeviceEvidence($user, $normalized, $coreReceipt);
            $result = [
                'command_uuid' => $normalized['command_uuid'],
                'transaction_id' => $coreReceipt['transaction_id'],
                'purchase_line_id' => $coreReceipt['purchase_line_id'],
                'unit_count' => count($deviceResults),
                'devices' => $deviceResults,
                'core_stock_changed' => false,
                'remaining_unassigned_units' => max(0, (int) $coreReceipt['quantity'] - ((int) $coreReceipt['assignment_count'] + count($deviceResults))),
            ];

            $commandReceipt->update([
                'status' => 'COMPLETED',
                'result_json' => $result,
                'completed_at' => now(),
            ]);

            return $result;
        });
    }

    public function execute(User $user, array $command, callable $corePurchaseWriter): array
    {
        $normalized = $this->normalizeCommand($command);

        if ((string) $user->business_id !== (string) $normalized['business_id']) {
            throw new AuthorizationException('Recommerce business scope denied.');
        }

        if (! $this->authorizationGate->allowsWrite(
            $user,
            'recommerce.receiving.post',
            $normalized['business_id'],
            $normalized['location_id'],
            $normalized['variation_id']
        )) {
            throw new AuthorizationException('Recommerce receiving scope denied.');
        }

        $this->assertCoreScope($user, $normalized);

        try {
            $requestHash = hash('sha256', json_encode(
                $normalized,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            ));
        } catch (\JsonException $exception) {
            throw new LogicException('Receiving command contains unsupported text encoding.', 0, $exception);
        }

        return DB::transaction(function () use ($user, $normalized, $requestHash, $corePurchaseWriter) {
            // Serialize tracked receives for one business before checking
            // identifiers. The database unique key remains the final guard,
            // while this lock prevents concurrent batches from both passing
            // the preflight read and reaching the core writer.
            DB::table('business')
                ->where('id', $normalized['business_id'])
                ->lockForUpdate()
                ->first();

            $existing = StockCommand::query()
                ->where('business_id', $normalized['business_id'])
                ->where('command_uuid', $normalized['command_uuid'])
                ->lockForUpdate()
                ->first();

            if ($existing) {
                if (! hash_equals($existing->request_hash, $requestHash)) {
                    throw new LogicException('Idempotency key was reused for a different request.');
                }

                if ($existing->status === 'COMPLETED') {
                    return $existing->result_json ?: [];
                }

                throw new ReceivingInProgressException('An equivalent receiving operation is already in progress.');
            }

            if ($this->stockReconciliationService) {
                $this->stockReconciliationService->assertTrackedReceiveMayProceed(
                    $normalized['business_id'],
                    $normalized['location_id'],
                    $normalized['variation_id']
                );
            }

            $commandReceipt = StockCommand::create([
                'business_id' => $normalized['business_id'],
                'command_uuid' => $normalized['command_uuid'],
                'command_type' => 'TRACKED_RECEIVE',
                'request_hash' => $requestHash,
                'actor_id' => $user->id,
                'status' => 'STARTED',
                'started_at' => now(),
            ]);

            $this->assertNoExistingIdentifiers($normalized);

            $coreReceipt = $corePurchaseWriter($normalized);
            $this->assertCoreReceipt($coreReceipt, $normalized, count($normalized['units']));

            $deviceResults = $this->createTrackedDeviceEvidence($user, $normalized, $coreReceipt);

            $result = [
                'command_uuid' => $normalized['command_uuid'],
                'transaction_id' => $coreReceipt['transaction_id'],
                'purchase_line_id' => $coreReceipt['purchase_line_id'],
                'unit_count' => count($deviceResults),
                'devices' => $deviceResults,
                'core_stock_changed' => true,
            ];

            $commandReceipt->update([
                'status' => 'COMPLETED',
                'result_json' => $result,
                'completed_at' => now(),
            ]);

            return $result;
        });
    }

    /**
     * Create the device, identity, ownership, custody, assignment, movement,
     * and immutable event evidence after the core receipt is established.
     */
    protected function createTrackedDeviceEvidence(User $user, array $normalized, array $coreReceipt): array
    {
        $deviceResults = [];
        foreach ($normalized['units'] as $ordinal => $unit) {
                $device = Device::create([
                    'business_id' => $normalized['business_id'],
                    'device_uuid' => (string) Str::uuid(),
                    'device_code' => 'SB-DV-TEMP-'.Str::random(24),
                    'category_code' => $normalized['category_code'],
                    'ownership_kind' => 'BUSINESS',
                    'custody_kind' => 'LOCATION',
                    'current_location_id' => $normalized['location_id'],
                    'product_id' => $normalized['product_id'],
                    'variation_id' => $normalized['variation_id'],
                    // A completed commercial purchase is now physically on hand and
                    // may enter the ordinary transfer/POS lifecycle.  The immutable
                    // RECEIVE movement and event retain the distinct receipt history.
                    'lifecycle_state' => 'AVAILABLE',
                    'stock_participation' => 'ON_HAND',
                    'acquired_at' => now(),
                    'created_by' => $user->id,
                    'updated_by' => $user->id,
                ]);

                $device->device_code = DeviceCode::forDeviceId((int) $device->id);
                $device->save();

                OwnershipPeriod::create([
                    'device_id' => $device->id,
                    'business_id' => $normalized['business_id'],
                    'owner_kind' => 'BUSINESS',
                    'starts_at' => now(),
                    'open_period_key' => $device->id,
                    'acquisition_transaction_id' => $coreReceipt['transaction_id'],
                    'reason' => 'PURCHASE',
                    'recorded_by' => $user->id,
                ]);

                $normalizedIdentifier = StrongIdentifierHasher::normalize($unit['identifier_value']);
                DeviceIdentifier::create([
                    'device_id' => $device->id,
                    'business_id' => $normalized['business_id'],
                    'identifier_type' => $unit['identifier_type'],
                    'normalized_hash' => StrongIdentifierHasher::hash($normalizedIdentifier),
                    'is_verified' => false,
                ]);

                DevicePurchaseAssignment::create([
                    'device_id' => $device->id,
                    'business_id' => $normalized['business_id'],
                    'transaction_id' => $coreReceipt['transaction_id'],
                    'purchase_line_id' => $coreReceipt['purchase_line_id'],
                    'unit_ordinal' => ((int) ($coreReceipt['unit_ordinal_start'] ?? 1)) + $ordinal,
                    'unit_acquisition_cost' => $unit['unit_acquisition_cost'],
                    'assigned_at' => now(),
                    'assigned_by' => $user->id,
                ]);

                $movement = DeviceMovement::create([
                    'device_id' => $device->id,
                    'business_id' => $normalized['business_id'],
                    'movement_type' => 'RECEIVE',
                    'to_custody_kind' => 'LOCATION',
                    'to_location_id' => $normalized['location_id'],
                    'source_transaction_id' => $coreReceipt['transaction_id'],
                    'source_line_id' => $coreReceipt['purchase_line_id'],
                    'source_line_type' => 'PURCHASE_LINE',
                    'command_uuid' => $normalized['command_uuid'],
                    'occurred_at' => now(),
                    'recorded_by' => $user->id,
                ]);

                CustodyPeriod::create([
                    'device_id' => $device->id,
                    'business_id' => $normalized['business_id'],
                    'custody_kind' => 'LOCATION',
                    'location_id' => $normalized['location_id'],
                    'starts_at' => $movement->occurred_at,
                    'open_period_key' => $device->id,
                    'source_movement_id' => $movement->id,
                    'reason' => 'RECEIVE',
                    'recorded_by' => $user->id,
                ]);

                ($this->deviceEventRecorder ?: new DeviceEventRecorder())->recordReceive(
                    $device,
                    $normalized['command_uuid'],
                    (int) $coreReceipt['transaction_id'],
                    (int) $coreReceipt['purchase_line_id'],
                    (int) $user->id
                );

                $deviceResults[] = [
                    'device_id' => $device->id,
                    'device_code' => $device->device_code,
                ];
        }

        return $deviceResults;
    }

    protected function normalizeCommand(array $command): array
    {
        foreach (['business_id', 'location_id', 'product_id', 'variation_id'] as $key) {
            if (! isset($command[$key]) || ! is_numeric($command[$key]) || (int) $command[$key] < 1) {
                throw new LogicException('Receiving command is missing a valid '.$key.'.');
            }
        }

        if (! isset($command['command_uuid'])
            || ! is_string($command['command_uuid'])
            || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $command['command_uuid']) !== 1) {
            throw new LogicException('Receiving command requires a valid idempotency UUID.');
        }

        $purchase = $command['purchase'] ?? [];
        if (! is_array($purchase)
            || ! isset($purchase['contact_id'], $purchase['transaction_date'], $purchase['unit_purchase_price'], $purchase['unit_purchase_price_inc_tax'], $purchase['unit_item_tax'])
            || ! is_numeric($purchase['contact_id'])
            || (int) $purchase['contact_id'] < 1
            || ! is_string($purchase['transaction_date'])
            || trim($purchase['transaction_date']) === ''
            || ! is_numeric($purchase['unit_purchase_price'])
            || ! is_numeric($purchase['unit_purchase_price_inc_tax'])
            || ! is_numeric($purchase['unit_item_tax'])
            || ! is_finite((float) $purchase['unit_purchase_price'])
            || ! is_finite((float) $purchase['unit_purchase_price_inc_tax'])
            || ! is_finite((float) $purchase['unit_item_tax'])
            || (float) $purchase['unit_purchase_price'] < 0
            || (float) $purchase['unit_purchase_price_inc_tax'] < 0
            || (float) $purchase['unit_item_tax'] < 0) {
            throw new LogicException('Receiving command requires a valid received purchase context.');
        }

        if (isset($purchase['tax_id'])
            && $purchase['tax_id'] !== ''
            && (! is_numeric($purchase['tax_id']) || (int) $purchase['tax_id'] < 1)) {
            throw new LogicException('Receiving command tax reference is invalid.');
        }

        if (isset($purchase['shipping_charges'])
            && (! is_numeric($purchase['shipping_charges'])
                || ! is_finite((float) $purchase['shipping_charges'])
                || (float) $purchase['shipping_charges'] < 0)) {
            throw new LogicException('Receiving command shipping charges are invalid.');
        }

        if (isset($purchase['additional_notes'])
            && (! is_string($purchase['additional_notes']) || strlen($purchase['additional_notes']) > 2000)) {
            throw new LogicException('Receiving command notes are invalid.');
        }

        $units = $command['units'] ?? [];
        $limit = (int) config('recommerce.receive_batch_limit', 50);

        if (! is_array($units) || $units === [] || count($units) > $limit) {
            throw new LogicException('Receiving command unit count is outside the approved batch limit.');
        }

        $normalizedUnits = [];
        $identifierHashes = [];
        foreach (array_values($units) as $unit) {
            if (! is_array($unit)
                || ! isset($unit['identifier_type'], $unit['identifier_value'])
                || ! is_string($unit['identifier_type'])
                || ! is_string($unit['identifier_value'])
                || preg_match('/^[A-Z0-9_]{1,40}$/', $unit['identifier_type']) !== 1) {
                throw new LogicException('Each receiving unit needs a valid identifier type and value.');
            }

            try {
                $normalizedIdentifier = StrongIdentifierHasher::normalize($unit['identifier_value']);
            } catch (\InvalidArgumentException $exception) {
                throw new LogicException('Receiving command contains an invalid identifier.', 0, $exception);
            }
            $identifierHash = StrongIdentifierHasher::hash($normalizedIdentifier);
            $identifierKey = $unit['identifier_type'].'|'.$identifierHash;
            if (isset($identifierHashes[$identifierKey])) {
                throw new LogicException('Receiving command contains a duplicate identifier.');
            }
            $identifierHashes[$identifierKey] = true;

            $unitCost = $unit['unit_acquisition_cost'] ?? null;
            if ($unitCost !== null
                && (! is_numeric($unitCost) || ! is_finite((float) $unitCost) || (float) $unitCost < 0)) {
                throw new LogicException('Receiving unit acquisition cost is invalid.');
            }

            $normalizedUnits[] = [
                'identifier_type' => $unit['identifier_type'],
                'identifier_value' => $normalizedIdentifier,
                'unit_acquisition_cost' => $unitCost === null ? null : (float) $unitCost,
            ];
        }

        return [
            'business_id' => (int) $command['business_id'],
            'location_id' => (int) $command['location_id'],
            'product_id' => (int) $command['product_id'],
            'variation_id' => (int) $command['variation_id'],
            'category_code' => isset($command['category_code']) ? (string) $command['category_code'] : null,
            'command_uuid' => strtolower($command['command_uuid']),
            'purchase' => [
                'contact_id' => (int) $purchase['contact_id'],
                'transaction_date' => trim($purchase['transaction_date']),
                'status' => 'received',
                'payment_status' => 'due',
                'unit_purchase_price' => (float) $purchase['unit_purchase_price'],
                'unit_purchase_price_inc_tax' => (float) $purchase['unit_purchase_price_inc_tax'],
                'unit_item_tax' => (float) $purchase['unit_item_tax'],
                'tax_id' => isset($purchase['tax_id']) && $purchase['tax_id'] !== '' ? (int) $purchase['tax_id'] : null,
                'shipping_charges' => isset($purchase['shipping_charges']) ? (float) $purchase['shipping_charges'] : 0,
                'additional_notes' => isset($purchase['additional_notes']) ? (string) $purchase['additional_notes'] : null,
            ],
            'units' => $normalizedUnits,
        ];
    }

    /**
     * Normalize an evidence-only command for stock that was already posted by
     * the native Purchase screen. Supplier, price, payments, and quantity are
     * read from the locked core purchase line rather than accepted from the
     * browser.
     */
    protected function normalizeExistingPurchaseAttachment(array $command): array
    {
        foreach (['business_id', 'location_id', 'product_id', 'variation_id', 'purchase_transaction_id', 'purchase_line_id'] as $key) {
            if (! isset($command[$key]) || ! is_numeric($command[$key]) || (int) $command[$key] < 1) {
                throw new LogicException('Purchase attachment command is missing a valid '.$key.'.');
            }
        }

        if (! isset($command['command_uuid'])
            || ! is_string($command['command_uuid'])
            || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $command['command_uuid']) !== 1) {
            throw new LogicException('Purchase attachment requires a valid idempotency UUID.');
        }

        $units = $command['units'] ?? [];
        $limit = (int) config('recommerce.receive_batch_limit', 50);
        if (! is_array($units) || $units === [] || count($units) > $limit) {
            throw new LogicException('Purchase attachment unit count is outside the approved batch limit.');
        }

        $normalizedUnits = [];
        $identifierHashes = [];
        foreach (array_values($units) as $unit) {
            if (! is_array($unit)
                || ! isset($unit['identifier_type'], $unit['identifier_value'])
                || ! is_string($unit['identifier_type'])
                || ! is_string($unit['identifier_value'])
                || preg_match('/^[A-Z0-9_]{1,40}$/', $unit['identifier_type']) !== 1) {
                throw new LogicException('Each purchase attachment unit needs a valid identifier type and value.');
            }

            try {
                $normalizedIdentifier = StrongIdentifierHasher::normalize($unit['identifier_value']);
            } catch (\InvalidArgumentException $exception) {
                throw new LogicException('Purchase attachment contains an invalid identifier.', 0, $exception);
            }

            $identifierHash = StrongIdentifierHasher::hash($normalizedIdentifier);
            $identifierKey = $unit['identifier_type'].'|'.$identifierHash;
            if (isset($identifierHashes[$identifierKey])) {
                throw new LogicException('Purchase attachment contains a duplicate identifier.');
            }
            $identifierHashes[$identifierKey] = true;

            $normalizedUnits[] = [
                'identifier_type' => $unit['identifier_type'],
                'identifier_value' => $normalizedIdentifier,
                'unit_acquisition_cost' => null,
            ];
        }

        return [
            'business_id' => (int) $command['business_id'],
            'location_id' => (int) $command['location_id'],
            'product_id' => (int) $command['product_id'],
            'variation_id' => (int) $command['variation_id'],
            'purchase_transaction_id' => (int) $command['purchase_transaction_id'],
            'purchase_line_id' => (int) $command['purchase_line_id'],
            'category_code' => isset($command['category_code']) ? (string) $command['category_code'] : null,
            'command_uuid' => strtolower($command['command_uuid']),
            'units' => $normalizedUnits,
        ];
    }

    protected function assertCoreScope(User $user, array $normalized): void
    {
        $permittedLocations = $user->permitted_locations($normalized['business_id']);
        if ($permittedLocations !== 'all'
            && ! collect($permittedLocations)->contains(fn ($locationId) => (string) $locationId === (string) $normalized['location_id'])) {
            throw new AuthorizationException('Core location scope denied.');
        }

        if (! BusinessLocation::query()
            ->where('id', $normalized['location_id'])
            ->where('business_id', $normalized['business_id'])
            ->exists()) {
            throw new LogicException('Receiving location is not in the requested business.');
        }

        if (! Product::query()
            ->where('id', $normalized['product_id'])
            ->where('business_id', $normalized['business_id'])
            ->exists()
            || ! Variation::query()
                ->where('id', $normalized['variation_id'])
                ->where('product_id', $normalized['product_id'])
                ->exists()) {
            throw new LogicException('Receiving product and variation scope is invalid.');
        }

        if (! Contact::query()
            ->where('id', $normalized['purchase']['contact_id'])
            ->where('business_id', $normalized['business_id'])
            ->exists()) {
            throw new LogicException('Receiving supplier scope is invalid.');
        }

        if ($normalized['purchase']['tax_id'] !== null
            && ! TaxRate::query()
                ->where('id', $normalized['purchase']['tax_id'])
                ->where('business_id', $normalized['business_id'])
                ->exists()) {
            throw new LogicException('Receiving tax scope is invalid.');
        }
    }

    protected function assertExistingPurchaseScope(User $user, array $normalized): void
    {
        $permittedLocations = $user->permitted_locations($normalized['business_id']);
        if ($permittedLocations !== 'all'
            && ! collect($permittedLocations)->contains(fn ($locationId) => (string) $locationId === (string) $normalized['location_id'])) {
            throw new AuthorizationException('Core location scope denied.');
        }

        if (! BusinessLocation::query()
            ->where('id', $normalized['location_id'])
            ->where('business_id', $normalized['business_id'])
            ->exists()) {
            throw new LogicException('Receiving location is not in the requested business.');
        }

        if (! Product::query()
            ->where('id', $normalized['product_id'])
            ->where('business_id', $normalized['business_id'])
            ->exists()
            || ! Variation::query()
                ->where('id', $normalized['variation_id'])
                ->where('product_id', $normalized['product_id'])
                ->exists()) {
            throw new LogicException('Receiving product and variation scope is invalid.');
        }
    }

    /**
     * The purchase line is the source of truth for an attachment. It must be
     * a fully received whole-unit line. Attachments may be posted in bounded
     * batches, but cannot exceed the unassigned unit count.
     */
    protected function lockExistingPurchaseLine(array $normalized, int $unitCount): array
    {
        $purchaseLine = DB::table('purchase_lines')
            ->join('transactions', 'transactions.id', '=', 'purchase_lines.transaction_id')
            ->where('purchase_lines.id', $normalized['purchase_line_id'])
            ->where('transactions.id', $normalized['purchase_transaction_id'])
            ->select([
                'purchase_lines.id as purchase_line_id',
                'purchase_lines.product_id',
                'purchase_lines.variation_id',
                'purchase_lines.quantity',
                'transactions.id as transaction_id',
                'transactions.business_id',
                'transactions.location_id',
                'transactions.type',
                'transactions.status',
            ])
            ->lockForUpdate()
            ->first();

        if (! $purchaseLine
            || $purchaseLine->type !== 'purchase'
            || $purchaseLine->status !== 'received'
            || (string) $purchaseLine->business_id !== (string) $normalized['business_id']
            || (string) $purchaseLine->location_id !== (string) $normalized['location_id']
            || (string) $purchaseLine->product_id !== (string) $normalized['product_id']
            || (string) $purchaseLine->variation_id !== (string) $normalized['variation_id']) {
            throw new LogicException('The selected POS purchase line is not an eligible received stock line.');
        }

        $quantity = (float) $purchaseLine->quantity;
        if ($quantity <= 0 || abs($quantity - round($quantity)) > 0.000001) {
            throw new LogicException('The selected POS purchase line must be a positive whole-unit line.');
        }

        $assignmentCount = DevicePurchaseAssignment::query()
            ->where('business_id', $normalized['business_id'])
            ->where('transaction_id', $normalized['purchase_transaction_id'])
            ->where('purchase_line_id', $normalized['purchase_line_id'])
            ->lockForUpdate()
            ->count();
        if ($assignmentCount + $unitCount > (int) round($quantity)) {
            throw new LogicException('The supplied Device count exceeds the unassigned units on the selected POS purchase line.');
        }

        return [
            'transaction_id' => (int) $purchaseLine->transaction_id,
            'purchase_line_id' => (int) $purchaseLine->purchase_line_id,
            'quantity' => $quantity,
            'business_id' => (int) $purchaseLine->business_id,
            'location_id' => (int) $purchaseLine->location_id,
            'product_id' => (int) $purchaseLine->product_id,
            'variation_id' => (int) $purchaseLine->variation_id,
            'assignment_count' => $assignmentCount,
            'unit_ordinal_start' => $assignmentCount + 1,
        ];
    }

    protected function assertNoExistingIdentifiers(array $normalized): void
    {
        foreach ($normalized['units'] as $unit) {
            $alreadyRegistered = DeviceIdentifier::query()
                ->where('business_id', $normalized['business_id'])
                ->where('identifier_type', $unit['identifier_type'])
                ->where('normalized_hash', StrongIdentifierHasher::hash($unit['identifier_value']))
                ->exists();

            if ($alreadyRegistered) {
                throw new LogicException('Receiving command contains an identifier already registered to a Device.');
            }
        }
    }

    protected function assertCoreReceipt($coreReceipt, array $normalized, int $unitCount): void
    {
        if (! is_array($coreReceipt)
            || ! isset(
            $coreReceipt['transaction_id'],
            $coreReceipt['purchase_line_id'],
            $coreReceipt['quantity'],
            $coreReceipt['business_id'],
            $coreReceipt['location_id'],
            $coreReceipt['product_id'],
            $coreReceipt['variation_id']
        )
            || (int) $coreReceipt['transaction_id'] < 1
            || (int) $coreReceipt['purchase_line_id'] < 1
            || abs((float) $coreReceipt['quantity'] - $unitCount) > 0.000001
            || (string) $coreReceipt['business_id'] !== (string) $normalized['business_id']
            || (string) $coreReceipt['location_id'] !== (string) $normalized['location_id']
            || (string) $coreReceipt['product_id'] !== (string) $normalized['product_id']
            || (string) $coreReceipt['variation_id'] !== (string) $normalized['variation_id']) {
            throw new LogicException('Core purchase callback did not return the exact expected receipt scope and quantity.');
        }
    }
}
