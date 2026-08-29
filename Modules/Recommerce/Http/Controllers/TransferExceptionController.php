<?php

namespace Modules\Recommerce\Http\Controllers;

use App\BusinessLocation;
use App\Transaction;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\ValidationException;
use LogicException;
use Modules\Recommerce\Entities\Device;
use Modules\Recommerce\Entities\DeviceTransferAssignment;
use Modules\Recommerce\Entities\DeviceTransferException;
use Modules\Recommerce\Services\DeviceTransferExceptionService;
use Modules\Recommerce\Support\AuthorizationGate;

class TransferExceptionController extends Controller
{
    public function show(int $transferId, AuthorizationGate $authorizationGate)
    {
        [$sellTransfer, $purchaseTransfer] = $this->transferPair($transferId);
        $assignments = DeviceTransferAssignment::query()->where('sell_transfer_transaction_id', $sellTransfer->id)->where('status', 'RESERVED')->whereNotNull('active_transfer_key')->get();
        $devices = Device::query()->whereIn('id', $assignments->pluck('device_id'))->orderBy('device_code')->get()->keyBy('id');
        $this->assertLocation($authorizationGate, $sellTransfer, $purchaseTransfer, $devices);
        $exceptions = DeviceTransferException::query()->where('sell_transfer_transaction_id', $sellTransfer->id)->orderByDesc('status')->orderBy('id')->get();
        $locations = BusinessLocation::query()->whereIn('id', [$sellTransfer->location_id, $purchaseTransfer->location_id])->pluck('name', 'id');

        return response()->view('recommerce::transfers.exceptions', compact('sellTransfer', 'purchaseTransfer', 'assignments', 'devices', 'exceptions', 'locations'))
            ->header('Cache-Control', 'no-store')
            ->header('Referrer-Policy', 'no-referrer');
    }

    public function receive(Request $request, int $transferId, DeviceTransferExceptionService $service)
    {
        [$sellTransfer, $purchaseTransfer] = $this->transferPair($transferId);
        try {
            $validated = $request->validate([
                'scanned_codes' => ['required', 'string', 'max:10000'],
                'evidence_note' => ['nullable', 'string', 'max:2000'],
            ]);
            $exceptions = $service->recordReceiving($request->user(), $sellTransfer->fresh(), $purchaseTransfer->fresh(), $validated['scanned_codes'], $validated['evidence_note'] ?? null);
        } catch (ValidationException $exception) {
            return back()->withErrors($exception->errors());
        } catch (AuthorizationException|LogicException $exception) {
            return back()->withErrors(['transfer' => $exception->getMessage()]);
        }

        return back()->with('status', $exceptions->count().' receiving exception(s) recorded.');
    }

    public function resolve(Request $request, int $exceptionId, DeviceTransferExceptionService $service)
    {
        $exception = DeviceTransferException::query()->findOrFail($exceptionId);
        try {
            $validated = $request->validate(['resolution_note' => ['required', 'string', 'max:2000']]);
            $service->resolve($request->user(), $exception, $validated['resolution_note']);
        } catch (ValidationException $validationException) {
            return back()->withErrors($validationException->errors());
        } catch (AuthorizationException|LogicException $exception) {
            return back()->withErrors(['exception' => $exception->getMessage()]);
        }

        return back()->with('status', 'Receiving exception resolved.');
    }

    protected function transferPair(int $transferId): array
    {
        $sellTransfer = Transaction::query()->where('business_id', auth()->user()->business_id)->where('type', 'sell_transfer')->findOrFail($transferId);
        $purchaseTransfer = Transaction::query()->where('business_id', $sellTransfer->business_id)->where('type', 'purchase_transfer')->where('transfer_parent_id', $sellTransfer->id)->firstOrFail();
        return [$sellTransfer, $purchaseTransfer];
    }

    protected function assertLocation(AuthorizationGate $authorizationGate, Transaction $sellTransfer, Transaction $purchaseTransfer, $devices): void
    {
        foreach ($devices as $device) {
            if (! $authorizationGate->allowsRead(auth()->user(), 'recommerce.device.transfer', $purchaseTransfer->business_id, $purchaseTransfer->location_id, $device->variation_id)) {
                abort(404);
            }
        }
        if ($devices->isEmpty()) {
            abort(404);
        }
    }
}
