<?php

namespace Modules\Recommerce\Services;

use App\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;
use LogicException;
use Modules\Recommerce\Entities\RepairJob;
use Modules\Recommerce\Entities\TradeInValuation;
use Modules\Recommerce\Support\AuthorizationGate;
use Modules\Recommerce\Support\RepairJobStateMachine;

class TradeInRefurbishmentService
{
    public function __construct(protected AuthorizationGate $authorizationGate, protected RepairJobIntakeService $repairIntake)
    {
    }

    public function create(User $user, TradeInValuation $valuation, ?string $notes = null): RepairJob
    {
        $device = $valuation->device()->first();
        if (! $device || $valuation->status !== TradeInValuation::STATUS_ACCEPTED || $device->ownership_kind !== 'BUSINESS'
            || $device->lifecycle_state !== 'PENDING_QC') {
            throw new LogicException('Only an accepted Device awaiting QC can enter internal refurbishment.');
        }
        if (! $this->authorizationGate->allowsWrite($user, 'recommerce.repair.intake', $valuation->business_id, $valuation->location_id, $valuation->variation_id)) {
            throw new AuthorizationException('Refurbishment intake scope denied.');
        }
        $existing = RepairJob::query()->where('business_id', $valuation->business_id)
            ->where('source_type', 'TRADE_IN_VALUATION')->where('source_id', $valuation->id)->first();
        if ($existing) {
            return $existing;
        }
        $inspection = (array) $valuation->inspection_json;
        $faults = collect((array) ($inspection['functional_observations'] ?? []))->filter(fn ($item) => ($item['outcome'] ?? null) === 'FAIL')
            ->map(fn ($item) => ($item['key'] ?? 'CHECK').': '.($item['notes'] ?? 'failed'))->implode("\n");
        $reportedFault = trim(($faults ?: '').($notes ? "\n".$notes : ''));

        return $this->repairIntake->create($user, [
            'location_id' => $valuation->location_id, 'device_id' => $device->id,
            'job_type' => RepairJobStateMachine::TYPE_INTERNAL_REFURBISHMENT, 'command_uuid' => (string) Str::uuid(),
            'reported_fault' => $reportedFault ?: 'Trade-in QC and refurbishment assessment required.',
            'cosmetic_condition' => $inspection['cosmetic_grade'] ?? null,
            'intake_snapshot_json' => ['trade_in_valuation_id' => $valuation->id, 'inspection' => $inspection],
            'source_type' => 'TRADE_IN_VALUATION', 'source_id' => $valuation->id,
            'customer_facing_update' => 'Internal refurbishment intake created from trade-in acquisition.',
        ]);
    }
}
