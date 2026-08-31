<?php

namespace Modules\Recommerce\Services;

use App\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use LogicException;
use Modules\Recommerce\Entities\Device;
use Modules\Recommerce\Entities\RepairJob;
use Modules\Recommerce\Entities\RepairStateTransition;
use Modules\Recommerce\Entities\TradeInValuation;
use Modules\Recommerce\Support\AuthorizationGate;
use Modules\Recommerce\Support\RepairJobStateMachine;

/** Releases an acquired device only after its internal refurbishment job has recorded QC evidence. */
class TradeInQcReleaseService
{
    public function __construct(protected AuthorizationGate $authorizationGate, protected DeviceEventRecorder $eventRecorder)
    {
    }

    public function release(User $user, TradeInValuation $valuation): Device
    {
        if ((int) $user->business_id !== (int) $valuation->business_id
            || ! $this->authorizationGate->allowsWrite($user, 'recommerce.tradein.accept', $valuation->business_id, $valuation->location_id, $valuation->variation_id)
            || ! $this->authorizationGate->allowsWrite($user, 'recommerce.repair.transition', $valuation->business_id, $valuation->location_id, $valuation->variation_id)) {
            throw new AuthorizationException('QC release scope denied.');
        }

        return DB::transaction(function () use ($user, $valuation): Device {
            $device = Device::query()->whereKey($valuation->device_id)->lockForUpdate()->first();
            if (! $device || $valuation->status !== TradeInValuation::STATUS_ACCEPTED
                || $device->ownership_kind !== 'BUSINESS' || $device->lifecycle_state !== 'PENDING_QC'
                || (int) $device->current_location_id !== (int) $valuation->location_id) {
                throw new LogicException('Only an accepted, branch-held Device awaiting QC can be released for sale.');
            }

            $job = RepairJob::query()->where('business_id', $valuation->business_id)
                ->where('source_type', 'TRADE_IN_VALUATION')->where('source_id', $valuation->id)
                ->lockForUpdate()->first();
            if (! $job || $job->state !== RepairJobStateMachine::STATE_READY) {
                throw new LogicException('Complete the linked internal refurbishment job before releasing this Device for sale.');
            }
            $qcPassed = RepairStateTransition::query()->where('repair_job_id', $job->id)
                ->where('to_state', RepairJobStateMachine::STATE_READY)
                ->orderByDesc('id')->get()->contains(function (RepairStateTransition $transition): bool {
                    $evidence = (array) $transition->evidence_json;
                    return ($evidence['qc_passed'] ?? false) === true || ($evidence['qc_waived'] ?? false) === true;
                });
            if (! $qcPassed) {
                throw new LogicException('The linked refurbishment job needs recorded QC pass evidence before release.');
            }

            $device->update([
                'lifecycle_state' => 'AVAILABLE', 'stock_participation' => 'ON_HAND',
                'updated_by' => $user->id, 'lock_version' => (int) $device->lock_version + 1,
            ]);
            $released = $device->fresh();
            $this->eventRecorder->recordLifecycle($released, 'TRADE_IN_QC_RELEASED', (int) $user->id, null, [
                'trade_in_valuation_id' => (int) $valuation->id, 'repair_job_id' => (int) $job->id,
            ]);

            return $released;
        });
    }
}
