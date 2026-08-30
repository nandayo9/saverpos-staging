<?php

namespace Modules\Recommerce\Services;

use App\User;
use App\Warranty;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Modules\Recommerce\Entities\RepairJob;
use Modules\Recommerce\Entities\WarrantyClaim;
use Modules\Recommerce\Entities\WarrantyClaimLine;
use Modules\Recommerce\Support\AuthorizationGate;
use Modules\Recommerce\Support\CohortPolicy;
use Throwable;

class WarrantyClaimService
{
    public const PERMISSION_MANAGE = 'recommerce.warranty.manage';

    public const STATUS_NOT_COVERED = 'NOT_COVERED';

    public const STATUS_IN_COVERAGE = 'IN_COVERAGE';

    public function __construct(
        protected AuthorizationGate $authorizationGate,
        protected CohortPolicy $cohortPolicy,
        protected ?RepairCollectionService $repeatService = null
    ) {
    }

    public function createClaim(User $user, RepairJob $sourceJob, string $commandUuid, array $evidence): WarrantyClaim
    {
        $this->assertClaimAccess($user, $sourceJob);

        return DB::transaction(function () use ($user, $sourceJob, $commandUuid, $evidence): WarrantyClaim {
            DB::table('business')->where('id', $sourceJob->business_id)->lockForUpdate()->first();

            $existing = WarrantyClaim::query()
                ->where('business_id', $sourceJob->business_id)
                ->where('command_uuid', $commandUuid)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                return $existing;
            }

            $decision = $this->decision($sourceJob->fresh(['device']), $evidence);
            $repairJobId = null;

            if ($decision['coverage_status'] === self::STATUS_IN_COVERAGE && ! empty($evidence['claimed_on'])) {
                $repeatJob = ($this->repeatService ?: app(RepairCollectionService::class))
                    ->startRepeat($user, $sourceJob->fresh(), $commandUuid);
                $repairJobId = $repeatJob->id;
            }

            $actorId = (int) $user->getAuthIdentifier();
            $claim = new WarrantyClaim;
            $claim->business_id = $sourceJob->business_id;
            $claim->location_id = $sourceJob->location_id;
            $claim->repair_job_id = $repairJobId;
            $claim->source_repair_job_id = $sourceJob->id;
            $claim->device_id = $sourceJob->device_id;
            $claim->warranty_id = $decision['warranty_id'];
            $claim->coverage_start_at = $decision['coverage_start_at'];
            $claim->coverage_end_at = $decision['coverage_end_at'];
            $claim->claim_requested_at = $decision['claim_requested_at'];
            $claim->policy_snapshot_json = $decision['policy_snapshot_json'];
            $claim->decision_evidence_json = $evidence;
            $claim->coverage_status = $decision['coverage_status'];
            $claim->decision_reason = $decision['decision_reason'];
            // Queryable copies of evidence that otherwise lives only inside the
            // JSON columns; reporting on claim date or policy should not have to
            // unpack them. policy_version stays null until policy versioning is
            // real - the snapshot's version_number is currently hardcoded.
            $claim->claimed_on = $evidence['claimed_on'] ?? null;
            $claim->policy_name = $decision['policy_snapshot_json']['policy_name'] ?? null;

            try {
                $claim->claim_uuid = (string) Str::uuid();
                $claim->claim_number = 'WAR-'.str_replace('-', '', (string) Str::uuid());
                $claim->command_uuid = $commandUuid;
            } catch (Throwable $exception) {
                throw new LogicException('Warranty claim identity could not be prepared.', 0, $exception);
            }

            $claim->created_by = $actorId;
            $claim->updated_by = $actorId;
            $claim->save();

            foreach ($decision['lines'] as $line) {
                WarrantyClaimLine::create(array_merge([
                    'warranty_claim_id' => $claim->id,
                    'business_id' => $claim->business_id,
                    'location_id' => $claim->location_id,
                ], $line));
            }

            return $claim->fresh(['lines']);
        });
    }

    public function decision(RepairJob $job, array $evidence = []): array
    {
        $requestedAt = now();

        if (! isset($evidence['claimed_on'])) {
            return [
                'warranty_id' => null,
                'coverage_start_at' => null,
                'coverage_end_at' => null,
                'claim_requested_at' => $requestedAt,
                'policy_snapshot_json' => [
                    'source_type' => $job->source_type,
                    'source_id' => $job->source_id,
                    'reason' => 'CLAIM_DATE_REQUIRED',
                ],
                'coverage_status' => self::STATUS_NOT_COVERED,
                'decision_reason' => 'The claimed_on date is required to evaluate the warranty term.',
                'lines' => [],
            ];
        }

        $sale = $this->sourceSale($job);

        if (! $sale) {
            return [
                'warranty_id' => null,
                'coverage_start_at' => null,
                'coverage_end_at' => null,
                'claim_requested_at' => $requestedAt,
                'policy_snapshot_json' => [
                    'source_type' => $job->source_type,
                    'source_id' => $job->source_id,
                    'source' => 'NONE',
                ],
                'coverage_status' => self::STATUS_NOT_COVERED,
                'decision_reason' => 'The source job has no finalized POS repair sale.',
                'lines' => [],
            ];
        }

        $claimedOn = Carbon::parse($evidence['claimed_on']);
        $start = Carbon::parse($sale->transaction_date);
        $warranty = $this->saleWarranty($job);

        if (! $warranty) {
            return [
                'warranty_id' => null,
                'coverage_start_at' => $start,
                'coverage_end_at' => null,
                'claim_requested_at' => $requestedAt,
                'policy_snapshot_json' => [
                    'source_type' => $job->source_type,
                    'source_id' => $job->source_id,
                    'reason' => 'WARRANTY_NOT_FOUND',
                ],
                'coverage_status' => self::STATUS_NOT_COVERED,
                'decision_reason' => 'No recorded warranty policy is available for the sale line.',
                'lines' => [],
            ];
        }

        $end = Carbon::parse($warranty->getEndDate($start->toDateTimeString()));

        if ($claimedOn->lessThan($start->copy()->startOfDay()) || $claimedOn->greaterThan($end)) {
            return [
                'warranty_id' => $warranty->id,
                'coverage_start_at' => $start,
                'coverage_end_at' => $end,
                'claim_requested_at' => $requestedAt,
                'policy_snapshot_json' => [
                    'source_type' => $job->source_type,
                    'source_id' => $job->source_id,
                    'warranty_id' => (int) $warranty->id,
                    'policy_name' => $warranty->name,
                    'duration' => (int) $warranty->duration,
                    'duration_type' => $warranty->duration_type,
                    'version_number' => 1,
                    'source' => 'APP_WARRANTY',
                ],
                'coverage_status' => self::STATUS_NOT_COVERED,
                'decision_reason' => 'The claimed_on date is outside the recorded warranty term.',
                'lines' => [],
            ];
        }

        $snapshot = [
            'source_type' => $job->source_type,
            'source_id' => $job->source_id,
            'warranty_id' => (int) $warranty->id,
            'policy_name' => (string) $warranty->name,
            'duration' => (int) $warranty->duration,
            'duration_type' => (string) $warranty->duration_type,
            'duration_text' => (string) $warranty->duration,
            'version_number' => 1,
            'source' => 'APP_WARRANTY',
        ];

        $total = round((float) $sale->final_total, 4);
        $requestedCovered = round((float) ($evidence['covered_amount'] ?? 0), 4);
        $coveredAmount = min(max($requestedCovered, 0.0), $total);
        $chargeableAmount = round(max($total - $coveredAmount, 0.0), 4);

        $lines = [];
        if ($coveredAmount > 0.0) {
            $lines[] = [
                'line_type' => 'LABOR',
                'billing_treatment' => 'COVERED',
                'description' => 'Labor covered by the recorded warranty term.',
                'amount' => $coveredAmount,
                'sort_order' => 1,
            ];
        }
        if ($chargeableAmount > 0.0) {
            $lines[] = [
                'line_type' => 'LABOR',
                'billing_treatment' => 'CHARGEABLE',
                'description' => 'Remaining labor billable in POS.',
                'amount' => $chargeableAmount,
                'sort_order' => 2,
            ];
        }

        return [
            'warranty_id' => $warranty->id,
            'coverage_start_at' => $start,
            'coverage_end_at' => $end,
            'claim_requested_at' => $requestedAt,
            'policy_snapshot_json' => $snapshot,
            'coverage_status' => self::STATUS_IN_COVERAGE,
            'decision_reason' => 'The claimed_on date is inside the recorded warranty term.',
            'lines' => $lines,
        ];
    }

    protected function sourceSale(RepairJob $job): ?object
    {
        if ($job->source_type !== 'POS_SELL') {
            return null;
        }

        return DB::table('transactions')
            ->where('id', $job->source_id)
            ->where('business_id', $job->business_id)
            ->where('type', 'sell')
            ->where('status', 'final')
            ->first();
    }

    protected function saleWarranty(RepairJob $job): ?Warranty
    {
        if (! $job->device || $job->device->variation_id === null) {
            return null;
        }

        $warrantyId = DB::table('transaction_sell_lines')
            ->join('products', 'products.id', '=', 'transaction_sell_lines.product_id')
            ->where('transaction_sell_lines.transaction_id', $job->source_id)
            ->where('transaction_sell_lines.variation_id', $job->device->variation_id)
            ->orderBy('transaction_sell_lines.id')
            ->value('products.warranty_id');

        if (! $warrantyId) {
            return null;
        }

        return Warranty::query()
            ->where('business_id', $job->business_id)
            ->whereKey((int) $warrantyId)
            ->first();
    }

    protected function assertClaimAccess(User $user, RepairJob $job): void
    {
        if (! $this->cohortPolicy->allowsReadLocation((int) $job->business_id, (int) $job->location_id)
            || (int) $user->business_id !== (int) $job->business_id
            || ! $this->authorizationGate->allowsWriteLocation(
                $user,
                self::PERMISSION_MANAGE,
                (int) $job->business_id,
                (int) $job->location_id
            )) {
            throw new AuthorizationException();
        }
    }
}
