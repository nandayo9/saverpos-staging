<?php

namespace Modules\Recommerce\Services;

use App\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;
use Modules\Recommerce\Entities\Device;
use Modules\Recommerce\Entities\DeviceInspection;
use Modules\Recommerce\Entities\DeviceIntakeObservation;
use Modules\Recommerce\Support\AuthorizationGate;

/**
 * Operational inspection is deliberately a Device-passport adjunct. It
 * controls the lifecycle release gate but never creates stock, movements,
 * purchases, accounting rows, or a parallel QC ledger.
 */
class DeviceInspectionService
{
    public const STATUS_PENDING = 'PENDING';
    public const STATUS_ASSIGNED = 'ASSIGNED';
    public const STATUS_IN_INSPECTION = 'IN_INSPECTION';
    public const STATUS_PASSED = 'PASSED';
    public const STATUS_FAILED = 'FAILED';

    public const OBSERVATION_TYPES = [
        'DAMAGED_PACKAGING',
        'VISIBLE_PHYSICAL_DAMAGE',
        'PRODUCT_MISMATCH',
        'MISSING_CHARGER',
        'UNREADABLE_IDENTIFIER',
        'SUPPLIER_DISCREPANCY',
        'OTHER',
    ];

    public const COST_OVERRIDE_REASONS = [
        'SUPPLIER_UNIT_PRICING',
        'BUNDLE_ALLOCATION',
        'INVOICE_CORRECTION',
        'MANAGEMENT_ADJUSTMENT',
        'OTHER',
    ];

    public function __construct(
        protected AuthorizationGate $authorizationGate,
        protected DeviceEventRecorder $eventRecorder
    ) {
    }

    /** Called inside the receiving transaction once the Device exists. */
    public function queueReceivedDevice(Device $device, array $receipt, User $user, array $observations = []): DeviceInspection
    {
        $inspection = DeviceInspection::query()->firstOrCreate(
            ['device_id' => $device->id],
            [
                'business_id' => $device->business_id,
                'location_id' => $device->current_location_id,
                'variation_id' => $device->variation_id,
                'purchase_transaction_id' => $receipt['transaction_id'] ?? null,
                'purchase_line_id' => $receipt['purchase_line_id'] ?? null,
                'status' => self::STATUS_PENDING,
                'received_at' => $device->acquired_at ?: now(),
                'created_by' => $user->id,
            ]
        );

        foreach ($this->normaliseObservations($observations) as $observation) {
            DeviceIntakeObservation::create([
                'device_id' => $device->id,
                'inspection_id' => $inspection->id,
                'business_id' => $device->business_id,
                'observation_type' => $observation['type'],
                'notes' => $observation['notes'],
                'status' => 'OPEN',
                'recorded_by' => $user->id,
                'recorded_at' => now(),
            ]);
        }

        return $inspection;
    }

    public function assign(User $user, array $deviceIds, int $inspectorId): int
    {
        if ($deviceIds === [] || $inspectorId < 1) {
            throw new InvalidArgumentException('Choose at least one Device and an inspector.');
        }
        if (! DB::table('users')->where('id', $inspectorId)->where('business_id', $user->business_id)->exists()) {
            throw new InvalidArgumentException('The selected inspector is unavailable in this business.');
        }

        return DB::transaction(function () use ($user, $deviceIds, $inspectorId): int {
            $assigned = 0;
            foreach (array_values(array_unique(array_map('intval', $deviceIds))) as $deviceId) {
                $inspection = DeviceInspection::query()->where('device_id', $deviceId)->lockForUpdate()->first();
                $device = Device::query()->whereKey($deviceId)->lockForUpdate()->first();
                if (! $inspection || ! $device || (int) $device->business_id !== (int) $user->business_id
                    || ! in_array($inspection->status, [self::STATUS_PENDING, self::STATUS_ASSIGNED, self::STATUS_IN_INSPECTION], true)
                    || ! $this->authorizationGate->allowsWrite($user, 'recommerce.inspection.assign', $device->business_id, $device->current_location_id, $device->variation_id)) {
                    throw new AuthorizationException('Inspection assignment scope denied.');
                }

                $inspection->update([
                    'status' => $inspection->status === self::STATUS_IN_INSPECTION ? self::STATUS_IN_INSPECTION : self::STATUS_ASSIGNED,
                    'assigned_to' => $inspectorId,
                    'assigned_at' => now(),
                ]);
                $this->eventRecorder->recordLifecycle($device->fresh(), 'INSPECTION_ASSIGNED', (int) $user->id, null, [
                    'inspection_id' => (int) $inspection->id,
                    'assigned_to' => $inspectorId,
                ]);
                $assigned++;
            }

            return $assigned;
        });
    }

    public function start(User $user, Device $device): DeviceInspection
    {
        return DB::transaction(function () use ($user, $device): DeviceInspection {
            $lockedDevice = Device::query()->whereKey($device->id)->lockForUpdate()->firstOrFail();
            $inspection = DeviceInspection::query()->where('device_id', $lockedDevice->id)->lockForUpdate()->firstOrFail();
            $this->assertInspectionScope($user, $lockedDevice, 'recommerce.inspection.complete');
            if (! in_array($inspection->status, [self::STATUS_PENDING, self::STATUS_ASSIGNED], true)) {
                throw new LogicException('This Device is not awaiting inspection start.');
            }
            $inspection->update(['status' => self::STATUS_IN_INSPECTION, 'started_at' => $inspection->started_at ?: now()]);
            $this->eventRecorder->recordLifecycle($lockedDevice->fresh(), 'INSPECTION_STARTED', (int) $user->id, null, ['inspection_id' => (int) $inspection->id]);

            return $inspection->fresh();
        });
    }

    public function complete(User $user, Device $device, bool $passed, ?string $notes = null): Device
    {
        $notes = $this->normaliseNotes($notes);

        return DB::transaction(function () use ($user, $device, $passed, $notes): Device {
            $lockedDevice = Device::query()->whereKey($device->id)->lockForUpdate()->firstOrFail();
            $inspection = DeviceInspection::query()->where('device_id', $lockedDevice->id)->lockForUpdate()->firstOrFail();
            $this->assertInspectionScope($user, $lockedDevice, 'recommerce.inspection.complete');
            if (! in_array($inspection->status, [self::STATUS_PENDING, self::STATUS_ASSIGNED, self::STATUS_IN_INSPECTION], true)
                || $lockedDevice->lifecycle_state !== 'RECEIVED_PENDING_INSPECTION') {
                throw new LogicException('Only a Device pending inspection can receive an inspection outcome.');
            }

            $inspection->update([
                'status' => $passed ? self::STATUS_PASSED : self::STATUS_FAILED,
                'started_at' => $inspection->started_at ?: now(),
                'completed_at' => now(),
                'completed_by' => $user->id,
                'outcome_notes' => $notes,
            ]);
            $lockedDevice->update([
                'lifecycle_state' => $passed ? 'AVAILABLE' : 'REFURBISHMENT_REQUIRED',
                'stock_participation' => 'ON_HAND',
                'updated_by' => $user->id,
                'lock_version' => (int) $lockedDevice->lock_version + 1,
            ]);
            $completed = $lockedDevice->fresh();
            $this->eventRecorder->recordLifecycle($completed, $passed ? 'INSPECTION_PASSED' : 'INSPECTION_FAILED', (int) $user->id, null, [
                'inspection_id' => (int) $inspection->id,
                'outcome_recorded' => true,
            ]);

            return $completed;
        });
    }

    public function normaliseObservations(array $observations): array
    {
        $normalised = [];
        foreach ($observations as $observation) {
            if (! is_array($observation)) {
                throw new InvalidArgumentException('An intake observation is invalid.');
            }
            $type = strtoupper(trim((string) ($observation['type'] ?? '')));
            if (! in_array($type, self::OBSERVATION_TYPES, true)) {
                throw new InvalidArgumentException('An intake observation type is invalid.');
            }
            $notes = $this->normaliseNotes($observation['notes'] ?? null);
            if ($type === 'OTHER' && $notes === null) {
                throw new InvalidArgumentException('Other intake observations require a note.');
            }
            $normalised[] = ['type' => $type, 'notes' => $notes];
        }

        return $normalised;
    }

    protected function assertInspectionScope(User $user, Device $device, string $permission): void
    {
        if ((int) $device->business_id !== (int) $user->business_id
            || ! $device->current_location_id
            || ! $this->authorizationGate->allowsWrite($user, $permission, $device->business_id, $device->current_location_id, $device->variation_id)) {
            throw new AuthorizationException('Inspection scope denied.');
        }
    }

    protected function normaliseNotes($notes): ?string
    {
        if ($notes === null || trim((string) $notes) === '') {
            return null;
        }
        $notes = trim((string) $notes);
        if ((function_exists('mb_strlen') ? mb_strlen($notes) : strlen($notes)) > 2000) {
            throw new InvalidArgumentException('Inspection notes are too long.');
        }

        return $notes;
    }
}
