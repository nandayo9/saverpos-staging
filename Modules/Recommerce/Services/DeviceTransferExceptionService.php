<?php

namespace Modules\Recommerce\Services;

use App\Transaction;
use App\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;
use Modules\Recommerce\Entities\Device;
use Modules\Recommerce\Entities\DeviceTransferAssignment;
use Modules\Recommerce\Entities\DeviceTransferException;
use Modules\Recommerce\Support\AuthorizationGate;

/**
 * Records receiving evidence for an already-authoritative stock transfer.
 * It never moves stock or changes Device custody; completion remains the
 * responsibility of the native transfer transaction after exceptions close.
 */
class DeviceTransferExceptionService
{
    public function __construct(protected AuthorizationGate $authorizationGate)
    {
    }

    public function recordReceiving(
        User $user,
        Transaction $sellTransfer,
        Transaction $purchaseTransfer,
        $scannedCodes,
        ?string $evidenceNote = null
    ): Collection {
        if (! config('recommerce.enabled') || ! config('recommerce.writes_enabled')) {
            return collect();
        }
        $this->assertTransfer($sellTransfer, $purchaseTransfer);
        $assignments = $this->assignments($sellTransfer);
        if ($assignments->isEmpty()) {
            throw new LogicException('Tracked transfer has no reserved device manifest.');
        }

        $expectedDevices = Device::query()
            ->where('business_id', $sellTransfer->business_id)
            ->whereIn('id', $assignments->pluck('device_id'))
            ->get()
            ->keyBy('id');
        foreach ($assignments as $assignment) {
            $device = $expectedDevices->get($assignment->device_id);
            if (! $device) {
                throw new LogicException('Tracked transfer manifest contains an unavailable device.');
            }
            $this->assertReceiverScope($user, $purchaseTransfer, (int) $device->variation_id);
        }

        $codes = $this->codes($scannedCodes);
        $observed = Device::query()
            ->where('business_id', $sellTransfer->business_id)
            ->whereIn('device_code', $codes)
            ->get()
            ->keyBy('device_code');
        $matchedIds = [];
        $substitutes = [];
        $extras = [];
        foreach ($codes as $code) {
            $device = $observed->get($code);
            if ($device && $expectedDevices->has($device->id)) {
                $matchedIds[] = $device->id;
            } elseif ($device) {
                $substitutes[] = $device;
            } else {
                $extras[] = ['code' => $code];
            }
        }

        $missing = $expectedDevices->except($matchedIds)->values();
        $exceptions = collect();
        foreach ($missing as $expected) {
            $substitute = array_shift($substitutes);
            if ($substitute) {
                $exceptions->push($this->persist($sellTransfer, $purchaseTransfer, 'SUBSTITUTED', $expected, $substitute, $substitute->device_code, $evidenceNote, $user->id));
            } else {
                $exceptions->push($this->persist($sellTransfer, $purchaseTransfer, 'MISSING', $expected, null, null, $evidenceNote, $user->id));
            }
        }
        foreach ($substitutes as $substitute) {
            $exceptions->push($this->persist($sellTransfer, $purchaseTransfer, 'EXTRA', null, $substitute, $substitute->device_code, $evidenceNote, $user->id));
        }
        foreach ($extras as $extra) {
            $exceptions->push($this->persist($sellTransfer, $purchaseTransfer, 'EXTRA', null, null, $extra['code'], $evidenceNote, $user->id));
        }

        return $exceptions;
    }

    public function resolve(User $user, DeviceTransferException $exception, string $resolutionNote): DeviceTransferException
    {
        if ($exception->status !== 'OPEN') {
            return $exception;
        }
        if (trim($resolutionNote) === '') {
            throw new InvalidArgumentException('A resolution note is required.');
        }
        $transfer = Transaction::query()->whereKey($exception->sell_transfer_transaction_id)->firstOrFail();
        $receipt = Transaction::query()->where('transfer_parent_id', $transfer->id)->where('type', 'purchase_transfer')->firstOrFail();
        $variationId = null;
        if ($exception->expected_device_id) {
            $variationId = Device::query()->whereKey($exception->expected_device_id)->value('variation_id');
        }
        if (! $variationId && $exception->observed_device_id) {
            $variationId = Device::query()->whereKey($exception->observed_device_id)->value('variation_id');
        }
        if (! $variationId || ! $this->authorizationGate->allowsWrite($user, 'recommerce.device.reverse_disposition', $receipt->business_id, $receipt->location_id, (int) $variationId)) {
            throw new AuthorizationException('Transfer exception resolution scope denied.');
        }
        return DB::transaction(function () use ($exception, $user, $resolutionNote) {
            $locked = DeviceTransferException::query()->whereKey($exception->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === 'OPEN') {
                $locked->update(['status' => 'RESOLVED', 'resolution_note' => trim($resolutionNote), 'resolved_by' => $user->id, 'resolved_at' => now()]);
            }
            return $locked->fresh();
        });
    }

    public function openFor(Transaction $sellTransfer): Collection
    {
        return DeviceTransferException::query()
            ->where('sell_transfer_transaction_id', $sellTransfer->id)
            ->where('status', 'OPEN')
            ->orderBy('id')
            ->get();
    }

    protected function persist(Transaction $sellTransfer, Transaction $purchaseTransfer, string $type, ?Device $expected, ?Device $observed, ?string $code, ?string $note, int $userId): DeviceTransferException
    {
        $hash = $code ? hash('sha256', strtoupper(trim($code))) : null;
        $query = DeviceTransferException::query()
            ->where('sell_transfer_transaction_id', $sellTransfer->id)
            ->where('exception_type', $type)
            ->where('status', 'OPEN');
        $expected ? $query->where('expected_device_id', $expected->id) : $query->whereNull('expected_device_id');
        $observed ? $query->where('observed_device_id', $observed->id) : $query->whereNull('observed_device_id');
        $hash ? $query->where('observed_device_code_hash', $hash) : $query->whereNull('observed_device_code_hash');
        $existing = $query->first();
        if ($existing) {
            return $existing;
        }
        return DeviceTransferException::create([
            'business_id' => $sellTransfer->business_id,
            'sell_transfer_transaction_id' => $sellTransfer->id,
            'purchase_transfer_transaction_id' => $purchaseTransfer->id,
            'expected_device_id' => $expected?->id,
            'observed_device_id' => $observed?->id,
            'exception_type' => $type,
            'status' => 'OPEN',
            'observed_device_code_hash' => $hash,
            'observed_device_code_hint' => $code ? substr(strtoupper(trim($code)), -4) : null,
            'evidence_note' => $note,
            'recorded_by' => $userId,
        ]);
    }

    protected function assignments(Transaction $sellTransfer): Collection
    {
        return DeviceTransferAssignment::query()
            ->where('sell_transfer_transaction_id', $sellTransfer->id)
            ->where('status', 'RESERVED')
            ->whereNotNull('active_transfer_key')
            ->lockForUpdate()
            ->get();
    }

    protected function assertTransfer(Transaction $sellTransfer, Transaction $purchaseTransfer): void
    {
        if ($sellTransfer->type !== 'sell_transfer' || ! in_array($sellTransfer->status, ['pending', 'in_transit'], true) || $purchaseTransfer->type !== 'purchase_transfer' || (int) $purchaseTransfer->transfer_parent_id !== (int) $sellTransfer->id) {
            throw new LogicException('Tracked transfer receiving is only available before completion.');
        }
    }

    protected function assertReceiverScope(User $user, Transaction $purchaseTransfer, int $variationId): void
    {
        if (! $this->authorizationGate->allowsWrite($user, 'recommerce.device.transfer', $purchaseTransfer->business_id, $purchaseTransfer->location_id, $variationId)) {
            throw new AuthorizationException('Transfer receiving scope denied.');
        }
    }

    protected function codes($value): array
    {
        $values = is_array($value) ? $value : preg_split('/[\s,]+/', (string) $value, -1, PREG_SPLIT_NO_EMPTY);
        return array_values(array_unique(array_map(fn ($code) => strtoupper(trim((string) $code)), $values)));
    }
}
