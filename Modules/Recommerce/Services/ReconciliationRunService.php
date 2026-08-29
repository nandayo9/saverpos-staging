<?php

namespace Modules\Recommerce\Services;

use App\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Recommerce\Entities\ReconciliationIssue;
use Modules\Recommerce\Entities\ReconciliationRun;
use Modules\Recommerce\Support\AuthorizationGate;

/**
 * Persists an immutable, safe snapshot of a reconciliation comparison.
 * Recording evidence never edits POS or tracked stock state.
 */
class ReconciliationRunService
{
    public function __construct(
        protected AuthorizationGate $authorizationGate,
        protected StockReconciliationService $stockReconciliationService
    ) {
    }

    public function record(
        User $user,
        int $businessId,
        int $locationId,
        int $variationId
    ): array {
        if (! $this->authorizationGate->allowsWrite(
            $user,
            'recommerce.stock.reconcile.record',
            $businessId,
            $locationId,
            $variationId
        )) {
            throw new AuthorizationException('Reconciliation evidence scope denied.');
        }

        $asOf = now();

        return DB::transaction(function () use ($user, $businessId, $locationId, $variationId, $asOf): array {
            $result = $this->stockReconciliationService->forVariation(
                $user,
                $businessId,
                $locationId,
                $variationId
            );
            $snapshot = $this->snapshot($result, $asOf->toISOString());
            $resultHash = hash('sha256', $this->canonicalJson($snapshot));

            $run = ReconciliationRun::create([
                'run_uuid' => (string) Str::uuid(),
                'business_id' => $businessId,
                'location_id' => $locationId,
                'variation_id' => $variationId,
                'requested_by' => $user->id,
                'as_of' => $asOf,
                'status' => $result['status'],
                'evidence_status' => $result['reconciliation_evidence_status'],
                'core_quantity' => $result['core_quantity'],
                'tracked_device_count' => $result['tracked_device_count'],
                'in_transfer_device_count' => $result['in_transfer_device_count'],
                'approved_legacy_balance' => $result['approved_legacy_balance'],
                'difference' => $result['difference'],
                'result_hash' => $resultHash,
                'snapshot_json' => $snapshot,
            ]);

            $issue = null;
            if ($result['status'] !== 'PASS') {
                $issue = ReconciliationIssue::create([
                    'reconciliation_run_id' => $run->id,
                    'business_id' => $businessId,
                    'location_id' => $locationId,
                    'variation_id' => $variationId,
                    'issue_type' => $this->issueType($result['status']),
                    'severity' => in_array($result['status'], ['MISMATCH', 'EXCEPTION'], true)
                        ? 'BLOCKING'
                        : 'REVIEW',
                    'status' => 'OPEN',
                    'detected_at' => $asOf,
                    'snapshot_json' => $snapshot,
                ]);
            }

            return [
                'run_id' => (int) $run->id,
                'run_uuid' => $run->run_uuid,
                'status' => $run->status,
                'result_hash' => $run->result_hash,
                'issue_id' => $issue?->id === null ? null : (int) $issue->id,
                'issue_status' => $issue?->status,
                'result' => $result,
            ];
        });
    }

    protected function snapshot(array $result, string $asOf): array
    {
        return [
            'as_of' => $asOf,
            'business_id' => (int) $result['business_id'],
            'location_id' => (int) $result['location_id'],
            'variation_id' => (int) $result['variation_id'],
            'status' => $result['status'],
            'reconciliation_evidence_status' => $result['reconciliation_evidence_status'],
            'core_quantity' => $result['core_quantity'],
            'tracked_device_count' => (int) $result['tracked_device_count'],
            'in_transfer_device_count' => (int) $result['in_transfer_device_count'],
            'approved_legacy_balance' => $result['approved_legacy_balance'],
            'difference' => $result['difference'],
            'serialization_profile_id' => $result['serialization_profile_id'] === null
                ? null
                : (int) $result['serialization_profile_id'],
            'legacy_balance_id' => $result['legacy_balance_id'] === null
                ? null
                : (int) $result['legacy_balance_id'],
        ];
    }

    protected function canonicalJson(array $snapshot): string
    {
        return (string) json_encode(
            $snapshot,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR
        );
    }

    protected function issueType(string $status): string
    {
        return match ($status) {
            'MISMATCH' => 'STOCK_MISMATCH',
            'EXCEPTION' => 'IN_TRANSFER_EXCEPTION',
            default => 'EVIDENCE_UNAVAILABLE',
        };
    }
}
