<?php

namespace Modules\Recommerce\Http\Controllers;

use App\Transaction;
use App\User;
use App\Utils\ProductUtil;
use App\Utils\TransactionUtil;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use LogicException;
use Modules\Recommerce\Entities\Device;
use Modules\Recommerce\Entities\DeviceTransferAssignment;
use Modules\Recommerce\Entities\ScanToken;
use Modules\Recommerce\Services\DeviceLifecycleService;
use Modules\Recommerce\Support\AuthorizationGate;
use Modules\Recommerce\Support\Identity\OpaqueScanToken;
use Modules\Recommerce\Support\Identity\ScanInput;

/** Staff-facing exact-device workflow over the native UltimatePOS transfer pair. */
class TransferController extends Controller
{
    public function show(int $transferId, AuthorizationGate $gate)
    {
        [$sell, $purchase] = $this->pair($transferId);
        $assignments = DeviceTransferAssignment::query()->where('sell_transfer_transaction_id', $sell->id)
            ->with('device.product')->orderBy('id')->get();
        $this->assertScope(auth()->user(), $gate, $sell, $purchase, $assignments);
        $tracked = $sell->sell_lines()->with('product')->get()->filter(fn ($line) => $this->tracked($sell->business_id, $line->variation_id));

        return response()->view('recommerce::transfers.show', compact('sell', 'purchase', 'assignments', 'tracked'))
            ->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer');
    }

    public function select(Request $request, int $transferId, DeviceLifecycleService $lifecycle, OpaqueScanToken $tokens)
    {
        [$sell, $purchase] = $this->pair($transferId);
        try {
            if ($sell->status !== 'pending') throw new LogicException('Devices can only be selected while this transfer is a draft.');
            $value = (string) $request->validate(['scan_value' => ['required', 'string', 'max:2048']])['scan_value'];
            $device = $this->resolve($sell->business_id, $value, $tokens);
            $selection = $this->selectionWith($sell, $device);
            DB::transaction(fn () => $lifecycle->synchroniseTransferReservation($request->user(), $sell->fresh(), $purchase->fresh(), $selection, true));
            return back()->with('status', $device->device_code.' selected for transfer.');
        } catch (\Throwable $e) {
            return back()->withErrors(['transfer' => $e instanceof AuthorizationException ? 'Cannot add this Device in the current source scope.' : $e->getMessage()]);
        }
    }

    public function dispatch(Request $request, int $transferId, DeviceLifecycleService $lifecycle)
    {
        [$sell, $purchase] = $this->pair($transferId);
        try {
            DB::transaction(function () use ($request, $sell, $purchase, $lifecycle) {
                if ($sell->fresh()->status !== 'pending') throw new LogicException('Only a draft transfer can be sent.');
                $lifecycle->dispatchTransfer($request->user(), $sell->fresh(), $purchase->fresh());
                $sell->update(['status' => 'in_transit']);
                $purchase->update(['status' => 'in_transit']);
            });
            return back()->with('status', 'Transfer sent. Expected Devices are now in transit.');
        } catch (\Throwable $e) {
            return back()->withErrors(['transfer' => $e instanceof AuthorizationException ? 'Transfer dispatch is not permitted for this branch.' : $e->getMessage()]);
        }
    }

    public function receive(Request $request, int $transferId, DeviceLifecycleService $lifecycle, OpaqueScanToken $tokens)
    {
        [$sell, $purchase] = $this->pair($transferId);
        try {
            if ($sell->status !== 'in_transit') throw new LogicException('Only an incoming transfer can receive Devices.');
            $data = $request->validate(['scan_value' => ['required', 'string', 'max:2048'], 'condition' => ['nullable', 'in:NORMAL,DAMAGED'], 'note' => ['nullable', 'string', 'max:1000']]);
            $device = $this->resolve($sell->business_id, $data['scan_value'], $tokens);
            $outcome = DB::transaction(fn () => $lifecycle->receiveTransferDevice($request->user(), $sell->fresh(), $purchase->fresh(), $device, $data['condition'] ?? 'NORMAL', $data['note'] ?? null));
            return back()->with('status', $outcome['status'] === 'ALREADY_RECEIVED' ? $device->device_code.' was already received.' : $device->device_code.' received.');
        } catch (\Throwable $e) {
            return back()->withErrors(['transfer' => $e instanceof AuthorizationException ? 'Transfer receiving is not permitted for this destination.' : $e->getMessage()]);
        }
    }

    public function complete(Request $request, int $transferId, DeviceLifecycleService $lifecycle, ProductUtil $productUtil, TransactionUtil $transactionUtil)
    {
        [$sell, $purchase] = $this->pair($transferId);
        try {
            DB::transaction(function () use ($request, $sell, $purchase, $lifecycle, $productUtil, $transactionUtil) {
                if ($sell->fresh()->status !== 'in_transit') throw new LogicException('Only an in-transit transfer can be completed.');
                // This assertion runs before aggregate stock is changed.
                $lifecycle->completeReceivedTransfer($request->user(), $sell->fresh(), $purchase->fresh());
                foreach ($sell->sell_lines()->with('product')->get() as $line) {
                    if ($line->product->enable_stock) {
                        $productUtil->decreaseProductQuantity($line->product_id, $line->variation_id, $sell->location_id, $line->quantity);
                        $productUtil->updateProductQuantity($purchase->location_id, $line->product_id, $line->variation_id, $line->quantity, 0, null, false);
                    }
                }
                $productUtil->adjustStockOverSelling($purchase);
                $transactionUtil->mapPurchaseSell(['id' => $sell->business_id, 'accounting_method' => $request->session()->get('business.accounting_method'), 'location_id' => $sell->location_id], $sell->sell_lines, 'purchase');
                $sell->update(['status' => 'final']);
                $purchase->update(['status' => 'received']);
            });
            return back()->with('status', 'Transfer complete. Received Devices are now at the destination.');
        } catch (\Throwable $e) {
            return back()->withErrors(['transfer' => $e instanceof AuthorizationException ? 'Transfer completion is not permitted for this destination.' : $e->getMessage()]);
        }
    }

    protected function pair(int $id): array
    {
        $sell = Transaction::query()->where('business_id', auth()->user()->business_id)->where('type', 'sell_transfer')->with('sell_lines.product')->findOrFail($id);
        $purchase = Transaction::query()->where('business_id', $sell->business_id)->where('type', 'purchase_transfer')->where('transfer_parent_id', $sell->id)->firstOrFail();
        return [$sell, $purchase];
    }

    protected function resolve(int $businessId, string $value, OpaqueScanToken $tokens): Device
    {
        $parsed = ScanInput::parse($value);
        if (! $parsed) throw new LogicException('Scan a SAVERBRO QR or enter a valid SAVERPOS Device ID.');
        if ($parsed['type'] === 'DEVICE_CODE') {
            return Device::query()->where('business_id', $businessId)->where('device_code', $parsed['value'])->firstOrFail();
        }
        $token = ScanToken::query()->where('business_id', $businessId)->where('subject_type', 'DEVICE')->where('status', 'ACTIVE')->where('token_hash', $tokens->hash($parsed['value']))->with('device')->first();
        if (! $token || ! $token->device) throw new LogicException('This SAVERBRO QR could not be resolved.');
        return $token->device;
    }

    protected function selectionWith(Transaction $sell, Device $incoming): array
    {
        $assignments = DeviceTransferAssignment::query()->where('sell_transfer_transaction_id', $sell->id)->where('status', 'RESERVED')->get()->groupBy('sell_line_id');
        $lines = $sell->sell_lines()->orderBy('id')->get();
        $target = $lines->first(fn ($line) => (int) $line->variation_id === (int) $incoming->variation_id && $this->tracked($sell->business_id, $line->variation_id) && $assignments->get($line->id, collect())->count() < (int) $line->quantity);
        if (! $target) throw new LogicException('This Device does not match an unfilled tracked transfer line.');
        $result = [];
        foreach ($lines as $line) {
            if (! $this->tracked($sell->business_id, $line->variation_id)) continue;
            $codes = $assignments->get($line->id, collect())->pluck('device_id')->map(fn ($id) => Device::query()->find($id)?->device_code)->filter()->all();
            if ($line->id === $target->id) $codes[] = $incoming->device_code;
            $result[] = ['product_id' => $line->product_id, 'variation_id' => $line->variation_id, 'recommerce_device_codes' => implode(' ', $codes)];
        }
        return $result;
    }

    protected function tracked(int $businessId, int $variationId): bool
    {
        return DB::table('recommerce_serialization_profiles')->where('business_id', $businessId)->where('variation_id', $variationId)->where('mode', 'TRACKED_REQUIRED')->whereNotNull('configured_by')->whereNotNull('approval_reference')->exists();
    }

    protected function assertScope(User $user, AuthorizationGate $gate, Transaction $sell, Transaction $purchase, $assignments): void
    {
        $variationIds = $assignments->pluck('device.variation_id')->filter()->all();
        if ($variationIds === []) $variationIds = $sell->sell_lines()->pluck('variation_id')->all();
        foreach (array_unique($variationIds) as $variationId) {
            if (! User::can_access_this_location($sell->location_id, $sell->business_id) && ! User::can_access_this_location($purchase->location_id, $purchase->business_id)) abort(404);
            if (! $gate->allowsRead($user, 'recommerce.device.transfer', $sell->business_id, $sell->location_id, $variationId)
                && ! $gate->allowsRead($user, 'recommerce.device.transfer', $purchase->business_id, $purchase->location_id, $variationId)) abort(404);
        }
    }
}
