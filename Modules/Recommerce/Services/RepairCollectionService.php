<?php

namespace Modules\Recommerce\Services;

use App\Transaction;
use App\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use LogicException;
use Modules\Recommerce\Entities\CustodyPeriod;
use Modules\Recommerce\Entities\Device;
use Modules\Recommerce\Entities\DeviceMovement;
use Modules\Recommerce\Entities\RepairJob;
use Modules\Recommerce\Entities\RepairPartUsage;
use Modules\Recommerce\Entities\RepairStateTransition;
use Modules\Recommerce\Support\AuthorizationGate;
use Modules\Recommerce\Support\RepairJobStateMachine;

/**
 * Customer-repair collection and custody close.
 *
 * POS remains the financial authority: the outstanding balance is read only
 * from that authority, never duplicated. Closing a job requires an explicit
 * QC outcome, fully resolved parts, a satisfied financial policy (paid or an
 * authorized override with a recorded reason), and collector evidence. The
 * handover moves the canonical device into CUSTOMER custody; stock is never
 * touched because customer-owned devices do not participate in stock.
 */
class RepairCollectionService
{
    public const PERMISSION_COLLECT = 'recommerce.repair.collection';

    public const PERMISSION_OVERRIDE = 'recommerce.repair.collection.override';

    public function __construct(
        protected AuthorizationGate $authorizationGate,
        protected DeviceEventRecorder $eventRecorder
    ) {
    }

    /**
     * POS-authority balance summary for the collection screen.
     *
     * @return array<string, mixed>
     */
    public function summary(User $user, RepairJob $job): array
    {
        $this->assertReadableJob($user, $job);

        $billedSale = $this->billedSale($job);
        $billedTotal = $billedSale === null ? 0.0 : round((float) $billedSale->final_total, 4);
        $paidAmount = $billedSale === null
            ? 0.0
            : round((float) DB::table('transaction_payments')->where('transaction_id', $billedSale->id)->sum('amount'), 4);
        $pendingParts = $this->pendingUsages($job);

        return [
            'sale_transaction_id' => $billedSale?->id,
            'billed_total' => $billedTotal,
            'paid_amount' => $paidAmount,
            'outstanding_amount' => max(0.0, round($billedTotal - $paidAmount, 4)),
            'pending_parts' => $pendingParts->count(),
        ];
    }

    /**
     * Close a READY customer job after QC, parts, financial, and custody
     * prerequisites pass, then hand the device to the collecting customer.
     *
     * @param array<string, mixed> $evidence
     */
    public function collect(User $user, RepairJob $job, array $evidence, ?string $overrideReason = null): RepairJob
    {
        $this->assertCollector($user, $job);
        $collectorName = trim((string) ($evidence['collector_name'] ?? ''));
        if ($collectorName === '') {
            throw new LogicException('Record the collector before handing over the device.');
        }

        return DB::transaction(function () use ($user, $job, $evidence, $collectorName, $overrideReason): RepairJob {
            DB::table('business')->where('id', $job->business_id)->lockForUpdate()->first();

            $lockedJob = RepairJob::query()->whereKey($job->getKey())->lockForUpdate()->first();
            if (! $lockedJob) {
                throw new LogicException('Repair job was not found.');
            }
            if (! $lockedJob->isCustomerRepair()) {
                throw new LogicException('Only customer Repair jobs are collected.');
            }
            if ($lockedJob->state !== RepairJobStateMachine::STATE_READY) {
                throw new LogicException('Only a repaired, QC-passed READY job can be collected.');
            }

            $this->assertQcOutcome($lockedJob);
            $this->assertPartsResolved($lockedJob);
            $financialPolicy = $this->assertFinancialPolicy($user, $lockedJob, $overrideReason);
            $lockedDevice = Device::query()->whereKey($lockedJob->device_id)->lockForUpdate()->firstOrFail();

            $closedJob = app(RepairJobTransitionService::class)->transition(
                $lockedJob,
                RepairJobStateMachine::STATE_CLOSED,
                [
                    'qc_satisfied' => true,
                    'parts_resolved' => true,
                    'financial_policy_satisfied' => true,
                    'custody_resolved' => true,
                    'collector_name' => $collectorName,
                    'collector_phone' => (string) ($evidence['collector_phone'] ?? ''),
                    'outstanding_amount' => $financialPolicy['outstanding_amount'],
                    'override_reason' => $financialPolicy['override_reason'] ?? null,
                ],
                (int) $lockedJob->lock_version,
                (int) $user->getAuthIdentifier()
            );

            $this->handOverDevice($lockedDevice, $closedJob, (int) $user->getAuthIdentifier());

            return $closedJob->fresh();
        });
    }

    /**
     * Start a repeat visit: the canonical Device is reused, the new job links
     * back to the closed original, and the closed job stays untouched.
     */
    public function startRepeat(User $user, RepairJob $closedJob, string $commandUuid): RepairJob
    {
        $this->assertRepeatAccess($user, $closedJob);

        return DB::transaction(function () use ($user, $closedJob, $commandUuid): RepairJob {
            $previous = RepairJob::query()->whereKey($closedJob->getKey())->lockForUpdate()->first();
            if (! $previous || $previous->state !== RepairJobStateMachine::STATE_CLOSED
                || ! $previous->isCustomerRepair()) {
                throw new LogicException('Only a closed customer Repair job can start a repeat visit.');
            }

            $device = Device::query()->whereKey($previous->device_id)->lockForUpdate()->firstOrFail();
            if ((int) $device->current_owner_contact_id !== (int) $previous->contact_id
                || $device->ownership_kind !== 'CUSTOMER') {
                throw new LogicException('A repeat visit needs the same collected device and customer.');
            }

            $existingRepeat = RepairJob::query()
                ->where('business_id', $previous->business_id)
                ->where('parent_repair_job_id', $previous->id)
                ->where('command_uuid', $commandUuid)
                ->first();
            if ($existingRepeat) {
                return $existingRepeat;
            }

            // The customer physically brings the collected unit back: record
            // the return-to-branch custody before the ordinary intake runs.
            \Modules\Recommerce\Entities\DeviceMovement::create([
                'device_id' => $device->id,
                'business_id' => $device->business_id,
                'movement_type' => 'REPEAT_REPAIR_INTAKE',
                'from_custody_kind' => 'CUSTOMER',
                'from_location_id' => null,
                'to_custody_kind' => 'LOCATION',
                'to_location_id' => $previous->location_id,
                'command_uuid' => $commandUuid,
                'occurred_at' => now(),
                'recorded_by' => (int) $user->getAuthIdentifier(),
                'reason' => 'Repeat visit device hand-in',
            ]);
            CustodyPeriod::query()
                ->where('device_id', $device->id)
                ->whereNotNull('open_period_key')
                ->update(['open_period_key' => null, 'ends_at' => now(), 'recorded_by' => (int) $user->getAuthIdentifier()]);
            $device->update([
                'custody_kind' => 'LOCATION',
                'current_location_id' => $previous->location_id,
                'lifecycle_state' => 'CUSTOMER_CUSTODY',
                'stock_participation' => 'NONE',
                'updated_by' => (int) $user->getAuthIdentifier(),
                'lock_version' => (int) $device->lock_version + 1,
            ]);
            CustodyPeriod::create([
                'device_id' => $device->id,
                'business_id' => $device->business_id,
                'custody_kind' => 'LOCATION',
                'location_id' => $previous->location_id,
                'starts_at' => now(),
                'open_period_key' => $device->id,
                'reason' => 'REPEAT_REPAIR_INTAKE',
                'recorded_by' => (int) $user->getAuthIdentifier(),
            ]);

            $newJob = app(RepairJobIntakeService::class)->create($user, [
                'command_uuid' => $commandUuid,
                'location_id' => $previous->location_id,
                'device_id' => $device->id,
                'job_type' => $previous->job_type,
                'contact_id' => $device->current_owner_contact_id,
                'category_code' => $device->category_code,
                'identifier_type' => 'DEVICE_CODE',
                'identifier_value' => $device->device_code,
                'reported_fault' => 'Repeat visit for the same device.',
                'access_status' => 'NO_LOCK',
                'checklist' => collect((array) config('recommerce.repair_intake_checklist', []))->map(
                    fn (array $check): array => [
                        'check_key' => $check['key'],
                        'label' => $check['label'],
                        'outcome' => 'PASS',
                        'notes' => null,
                    ]
                )->values()->all(),
            ]);

            $linked = RepairJob::query()->whereKey($newJob->getKey())->lockForUpdate()->firstOrFail();
            $linked->parent_repair_job_id = $previous->id;
            $linked->save();

            return $linked->fresh();
        });
    }

    /** @return \Illuminate\Support\Collection<int, \Modules\Recommerce\Entities\RepairPartUsage> */
    protected function pendingUsages(RepairJob $job)
    {
        return RepairPartUsage::query()
            ->where('business_id', $job->business_id)
            ->where('repair_job_id', $job->getKey())
            ->where('consumption_path', 'CUSTOMER')
            ->where('status', 'INSTALLED_PENDING_BILLING')
            ->orderBy('id')
            ->get();
    }

    /** Pending installed-but-unbilled customer parts keep the job open. */
    protected function assertPartsResolved(RepairJob $job): void
    {
        $pending = $this->pendingUsages($job);
        if ($pending->isNotEmpty()) {
            throw new LogicException('Installed parts must be billed before collection.');
        }
    }

    /** Verify the QC evidence recorded on the transition that reached READY. */
    protected function assertQcOutcome(RepairJob $job): void
    {
        $transition = RepairStateTransition::query()
            ->where('repair_job_id', $job->getKey())
            ->where('to_state', RepairJobStateMachine::STATE_READY)
            ->orderByDesc('occurred_at')
            ->first();
        $evidence = $transition->evidence_json ?? [];
        if ($transition === null) {
            throw new LogicException('A recorded QC outcome is required before collection.');
        }
        if ($transition->from_state !== RepairJobStateMachine::STATE_QC) {
            throw new LogicException('Collection requires a completed QC pass for this job.');
        }
        if (($evidence['qc_passed'] ?? false) !== true && ($evidence['qc_waived'] ?? false) !== true) {
            throw new LogicException('Collection requires a passed or authorized waiver QC outcome.');
        }
    }

    /** POS remains the only financial authority for the outstanding balance. */
    protected function assertFinancialPolicy(User $user, RepairJob $job, ?string $overrideReason): array
    {
        $billedSale = $this->billedSale($job);
        $billedTotal = $billedSale === null ? 0.0 : round((float) $billedSale->final_total, 4);
        $paidAmount = $billedSale === null
            ? 0.0
            : round((float) DB::table('transaction_payments')
                ->where('transaction_id', $billedSale->id)
                ->sum('amount'), 4);
        $outstanding = max(0.0, round($billedTotal - $paidAmount, 4));

        if ($outstanding <= 0.0001) {
            return ['outstanding_amount' => 0.0, 'override' => false];
        }

        if ($overrideReason === null || trim((string) $overrideReason) === '') {
            throw new LogicException('The POS sale has an outstanding balance; collection requires a recorded override.');
        }

        if (! $this->authorizationGate->allowsWriteLocation(
            $user,
            self::PERMISSION_OVERRIDE,
            $job->business_id,
            $job->location_id
        )) {
            throw new AuthorizationException();
        }

        return ['outstanding_amount' => $outstanding, 'override_reason' => $overrideReason, 'override' => true];
    }

    /** POS linkage for the collection summary; POS stays authority. */
    protected function billedSale(RepairJob $job): ?Transaction
    {
        if ($job->source_type === null || $job->source_id === null) {
            return null;
        }

        return Transaction::query()
            ->where('business_id', $job->business_id)
            ->where('id', (int) $job->source_id)
            ->where('type', 'sell')
            ->where('status', 'final')
            ->first();
    }

    /** Pending installed-but-unbilled customer parts still waiting for billing. */
    protected function handOverDevice(Device $device, RepairJob $closedJob, int $actorId): void
    {
        CustodyPeriod::query()
            ->where('device_id', $device->id)
            ->whereNotNull('open_period_key')
            ->update(['open_period_key' => null, 'ends_at' => now(), 'recorded_by' => $actorId]);

        DeviceMovement::create([
            'device_id' => $device->id,
            'business_id' => $device->business_id,
            'movement_type' => 'CUSTOMER_REPAIR_COLLECTED',
            'from_custody_kind' => 'LOCATION',
            'from_location_id' => $device->current_location_id,
            'to_custody_kind' => 'CUSTOMER',
            'to_location_id' => null,
            'source_transaction_id' => $closedJob->source_id,
            'source_line_type' => null,
            'occurred_at' => now(),
            'recorded_by' => $actorId,
        ]);
        $device->update([
            'custody_kind' => 'CUSTOMER',
            'current_location_id' => null,
            'lifecycle_state' => 'CUSTOMER_CUSTODY',
            'stock_participation' => 'NONE',
            'updated_by' => $actorId,
            'lock_version' => (int) $device->lock_version + 1,
        ]);
        CustodyPeriod::create([
            'device_id' => $device->id,
            'business_id' => $device->business_id,
            'custody_kind' => 'CUSTOMER',
            'starts_at' => now(),
            'open_period_key' => $device->id,
            'reason' => 'CUSTOMER_REPAIR_COLLECTED',
            'recorded_by' => $actorId,
        ]);
        $this->eventRecorder->recordLifecycle($device->fresh(), 'CUSTOMER_REPAIR_COLLECTED', $actorId, null, [
            'repair_job_id' => (int) $closedJob->id,
        ]);
    }

    protected function assertCollector($user, RepairJob $job): void
    {
        if (! User::can_access_this_location($job->location_id, $user->business_id)
            || (int) $job->business_id !== (int) $user->business_id
            || $job->state === RepairJobStateMachine::STATE_CLOSED
            || ! $this->authorizationGate->allowsWriteLocation(
                $user,
                self::PERMISSION_COLLECT,
                $job->business_id,
                $job->location_id
            )) {
            throw new AuthorizationException();
        }
    }

    /** A closed job passes the collect guard only for repeat-visit intake. */
    protected function assertRepeatAccess(User $user, RepairJob $closedJob): void
    {
        if ($closedJob->state !== RepairJobStateMachine::STATE_CLOSED) {
            $this->assertCollector($user, $closedJob);

            return;
        }

        if (! $this->authorizationGate->allowsWriteLocation(
            $user,
            'recommerce.repair.intake',
            $closedJob->business_id,
            $closedJob->location_id
        )) {
            throw new AuthorizationException();
        }
    }

    protected function assertReadableJob($user, RepairJob $job): void
    {
        if (! User::can_access_this_location($job->location_id, $user->business_id)
            || (int) $job->business_id !== (int) $user->business_id
            || ! $this->authorizationGate->allowsRead(
                $user,
                'recommerce.repair.view',
                $job->business_id,
                $job->location_id
            )) {
            throw new AuthorizationException();
        }
    }
}
