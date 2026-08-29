<?php

namespace Modules\Recommerce\Services;

use Illuminate\Support\Facades\DB;
use LogicException;
use Modules\Recommerce\Entities\RepairJob;
use Modules\Recommerce\Entities\RepairStateTransition;
use Modules\Recommerce\Support\RepairJobStateMachine;
use Modules\Recommerce\Services\RepairQuoteService;

class RepairJobTransitionService
{
    public function transition(RepairJob $job, string $toState, array $evidence = [], ?int $expectedLockVersion = null, ?int $actorId = null): RepairJob
    {
        return DB::transaction(function () use ($job, $toState, $evidence, $expectedLockVersion, $actorId): RepairJob {
            $locked = RepairJob::query()->whereKey($job->getKey())->lockForUpdate()->first();

            if (! $locked) {
                throw new LogicException('Repair job was not found.');
            }

            if ($expectedLockVersion !== null && (int) $locked->lock_version !== $expectedLockVersion) {
                throw new LogicException('Repair job changed; reload before retrying.');
            }

            RepairJobStateMachine::assertTransition($locked, $toState, $evidence);
            app(RepairQuoteService::class)->assertWorkAuthorised($locked, $toState, $evidence);

            $fromState = $locked->state;

            $locked->state = $toState;
            $locked->resolution_code = $evidence['resolution_code'] ?? $locked->resolution_code;
            $locked->lock_version = (int) $locked->lock_version + 1;
            $locked->updated_by = $actorId;

            if ($toState === RepairJobStateMachine::STATE_CLOSED) {
                $locked->closed_at = now();
            }

            $locked->save();

            RepairStateTransition::create([
                'business_id' => $locked->business_id,
                'location_id' => $locked->location_id,
                'repair_job_id' => $locked->id,
                'transition_uuid' => (string) \Illuminate\Support\Str::uuid(),
                'from_state' => $fromState,
                'to_state' => $toState,
                'evidence_json' => $evidence,
                'actor_id' => $actorId,
                'occurred_at' => now(),
            ]);

            return $locked->fresh();
        });
    }
}
