<?php

namespace Modules\Recommerce\Services;

use App\Transaction;
use App\User;
use App\Variation;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use LogicException;
use Modules\Recommerce\Entities\RepairJob;
use Modules\Recommerce\Entities\RepairPartUsage;
use Modules\Recommerce\Entities\RepairQuote;
use Modules\Recommerce\Entities\RepairQuoteLine;
use Modules\Recommerce\Support\AuthorizationGate;
use Modules\Recommerce\Support\RepairJobStateMachine;

/**
 * Customer-repair billing coordinator.
 *
 * Ultimate POS remains the authority for invoices, tax, payments, and stock.
 * This service projects the billable repair scope, links one finalized POS
 * sale to the job, consumes every installed customer part exactly once, and
 * releases the billed state when that sale is deleted. It never writes
 * payments, tax, or invoice numbers and never consumes a part twice.
 */
class RepairBillingService
{
    public const PERMISSION_BILL = 'recommerce.repair.billing';

    public const PERMISSION_VIEW_COST = 'recommerce.repair.view_cost';

    public const JOB_SOURCE_TYPE = 'POS_SELL';

    public const BILLING_STATUS_PENDING = 'INSTALLED_PENDING_BILLING';

    public const BILLING_STATUS_BILLED = 'CONSUMED';

    public function __construct(
        protected AuthorizationGate $authorizationGate
    ) {
    }

    /**
     * Read-only projected invoice for the operator's POS screen.
     *
     * @return array{parts: array<int, array<string, mixed>>, services: array<int, array<string, mixed>>}
     */
    public function project(User $user, RepairJob $job): array
    {
        $this->assertReadableJob($user, $job);
        if (! $job->isCustomerRepair()) {
            throw new LogicException('Only customer Repair jobs bill through POS.');
        }

        $pending = $this->pendingUsages($job);
        $parts = [];
        foreach ($pending as $usage) {
            $variation = Variation::query()
                ->with('product')
                ->where('business_id', $job->business_id)
                ->whereKey($usage->variation_id)
                ->first();
            $parts[] = [
                'usage_id' => (int) $usage->getKey(),
                'variation_id' => (int) $usage->variation_id,
                'description' => optional($usage->variation->product)->name ?? ('Variation '.$usage->variation_id),
                'quantity' => (float) $usage->quantity,
                'unit_price' => $usage->variation !== null && $usage->variation->sell_price_inc_tax !== null
                    ? (float) $usage->variation->sell_price_inc_tax
                    : null,
            ];
        }

        $services = [];
        $approvedQuote = $this->latestApprovedQuote($job);
        if ($approvedQuote !== null) {
            foreach ($this->unbilledQuoteServiceLines($approvedQuote) as $line) {
                $services[] = [
                    'quote_line_id' => (int) $line->id,
                    'pos_variation_id' => $line->source_type === 'POS_VARIATION' && $line->source_id !== null
                        ? (int) $line->source_id
                        : null,
                    'description' => $line->description,
                    'quantity' => (float) $line->quantity,
                    'unit_amount' => (float) $line->unit_amount,
                    'tax_amount' => (float) $line->tax_amount,
                    'line_total_amount' => (float) $line->line_total_amount,
                ];
            }
        }

        return ['parts' => $parts, 'services' => $services];
    }

    /**
     * Link one finalized POS sell transaction to the Repair job, consume every
     * installed pending customer part exactly once, and store the linkage.
     *
     * Retrying with the same sale returns the same state without consuming
     * again. A sale that does not cover every pending installed part is a
     * billing reconciliation failure, never a partial consumption.
     */
    public function linkSale(User $user, RepairJob $job, string $commandUuid, int $saleTransactionId): RepairJob
    {
        $this->authorize($user, $job);
        if (! $job->isCustomerRepair()) {
            throw new LogicException('Only customer Repair jobs bill through POS.');
        }

        return DB::transaction(function () use ($user, $job, $commandUuid, $saleTransactionId): RepairJob {
            DB::table('business')->where('id', $job->business_id)->lockForUpdate()->first();

            $lockedJob = RepairJob::query()->whereKey($job->getKey())->lockForUpdate()->first();
            if (! $lockedJob || $lockedJob->state === RepairJobStateMachine::STATE_CLOSED) {
                throw new LogicException('A closed Repair job cannot be billed.');
            }
            if (! $lockedJob->isCustomerRepair()) {
                throw new LogicException('Only customer Repair jobs bill through POS.');
            }

            $otherJobLinked = RepairJob::query()
                ->where('business_id', $job->business_id)
                ->whereKeyNot($job->getKey())
                ->where('source_type', self::JOB_SOURCE_TYPE)
                ->where('source_id', $saleTransactionId)
                ->exists();
            if ($otherJobLinked) {
                throw new LogicException('This POS sale is already linked to another Repair job.');
            }

            if ($lockedJob->source_type === self::JOB_SOURCE_TYPE
                && $lockedJob->source_id !== null
                && (int) $lockedJob->source_id !== $saleTransactionId) {
                throw new LogicException('This Repair job is already billed to another POS sale. Release the existing billed state first.');
            }

            $sale = $this->validatedSale($lockedJob, $saleTransactionId);

            $pending = $this->pendingUsages($lockedJob);
            $lines = DB::table('transaction_sell_lines')
                ->where('transaction_id', $saleTransactionId)
                ->orderBy('id')
                ->get(['id', 'variation_id', 'quantity']);

            if ($pending->isNotEmpty()) {
                $requiredByVariation = $this->pendingUsagesGrouped($pending);
                foreach ($requiredByVariation as $variationId => $requiredQuantity) {
                    $covered = (float) $lines->where('variation_id', $variationId)->sum('quantity');
                    if ($covered + 0.0001 < $requiredQuantity) {
                        throw new LogicException(
                            'The finalized POS sale does not cover all installed pending parts. '
                            .$this->formatAmount($covered).' units were found for '
                            .$this->formatAmount($requiredQuantity).' installed units of variation '.$variationId.'.'
                        );
                    }
                }
            }

            foreach ($pending as $usage) {
                $matchedLine = $lines->first(fn ($line) => (int) $line->variation_id === (int) $usage->variation_id);
                $lockedUsage = RepairPartUsage::query()->whereKey($usage->getKey())->lockForUpdate()->first();
                if (! $lockedUsage || $lockedUsage->status !== 'INSTALLED_PENDING_BILLING') {
                    throw new LogicException('Only an installed, unbilled part can be billed from a finalized POS sale.');
                }

                $lockedUsage->status = 'CONSUMED';
                $lockedUsage->source_type = 'SALE';
                $lockedUsage->source_transaction_id = $saleTransactionId;
                $lockedUsage->source_line_id = (int) $matchedLine->id;
                $lockedUsage->resolved_at = now();
                $lockedUsage->recorded_by = $user->getAuthIdentifier();
                $lockedUsage->save();
                $lockedUsage->reservation()->update(['status' => 'CONSUMED']);
            }

            if ($lockedJob->source_type === null && $lockedJob->source_id === null) {
                $lockedJob->source_type = self::JOB_SOURCE_TYPE;
                $lockedJob->source_id = $saleTransactionId;
                $lockedJob->updated_by = $user->getAuthIdentifier();
                $lockedJob->save();
            }

            return $lockedJob->fresh();
        });
    }

    /**
     * Reversal of billed state. A POS sale was deleted, voided to draft, or an
     * explicit POS correction returned the part. Installed customer parts go
     * back to INSTALLED_PENDING_BILLING so they can be billed again; history
     * stays intact because the POS transaction is deleted by POS itself.
     */
    public function releaseSale(User $user, RepairJob $job, int $saleTransactionId, string $reason): int
    {
        $this->authorize($user, $job);
        $reason = trim($reason);
        if ($reason === '') {
            throw new LogicException('A reversal reason is required.');
        }

        return DB::transaction(function () use ($user, $job, $saleTransactionId, $reason): int {
            DB::table('business')->where('id', $job->business_id)->lockForUpdate()->first();

            $billed = RepairPartUsage::query()
                ->where('business_id', $job->business_id)
                ->where('repair_job_id', $job->getKey())
                ->where('consumption_path', 'CUSTOMER')
                ->where('status', 'CONSUMED')
                ->where('source_type', 'SALE')
                ->where('source_transaction_id', $saleTransactionId)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            if ($billed->isEmpty()) {
                return 0;
            }

            $lockedJob = RepairJob::query()->whereKey($job->getKey())->lockForUpdate()->firstOrFail();
            if ($lockedJob->state === RepairJobStateMachine::STATE_CLOSED) {
                throw new LogicException('A closed Repair job cannot reverse billed parts.');
            }

            foreach ($billed as $usage) {
                $locked = RepairPartUsage::query()->whereKey($usage->getKey())->lockForUpdate()->first();
                $locked->status = 'INSTALLED_PENDING_BILLING';
                $locked->source_type = null;
                $locked->source_transaction_id = null;
                $locked->source_line_id = null;
                $locked->resolved_at = null;
                $locked->recorded_by = $user->getAuthIdentifier();
                $locked->save();
                $locked->reservation()->update(['status' => 'ISSUED']);
            }

            if ((int) $lockedJob->source_id === $saleTransactionId
                && ($lockedJob->source_type === self::JOB_SOURCE_TYPE || $lockedJob->source_type === null)) {
                $lockedJob->source_type = null;
                $lockedJob->source_id = null;
                $lockedJob->updated_by = $user->getAuthIdentifier();
                $lockedJob->save();
            }

            return $billed->count();
        });
    }

    /**
     * Core sale-deletion hook. Reverts every billed customer part usage that
     * references the deleted transaction so the billed state never points at a
     * missing financial record. Safe to call from every POS deletion path; it
     * is a no-op when Recommerce is disabled and never masks a POS deletion.
     */
    public function releaseSaleForDeletedTransaction(User $user, Transaction $sale, string $reason): int
    {
        if (! config('recommerce.enabled', false) || ! config('recommerce.writes_enabled', false)) {
            return 0;
        }

        $jobs = RepairJob::query()
            ->where('business_id', $sale->business_id)
            ->where('source_type', self::JOB_SOURCE_TYPE)
            ->where('source_id', $sale->id)
            ->get();
        if ($jobs->isEmpty()) {
            return 0;
        }

        $released = 0;
        foreach ($jobs as $job) {
            try {
                $released += $this->releaseSale($user, $job, (int) $sale->id, $reason);
            } catch (\Throwable $exception) {
                // A POS deletion must never be masked by a Recommerce-only error.
                report($exception);
                continue;
            }
        }

        return $released;
    }

    /** Pending installed-but-unbilled customer parts for this job. */
    protected function pendingUsages(RepairJob $job)
    {
        return RepairPartUsage::query()
            ->with('variation.product')
            ->where('business_id', $job->business_id)
            ->where('repair_job_id', $job->getKey())
            ->where('consumption_path', 'CUSTOMER')
            ->where('status', 'INSTALLED_PENDING_BILLING')
            ->whereNull('source_transaction_id')
            ->orderBy('id')
            ->get();
    }

    /** @return \Illuminate\Support\Collection<string, float> */
    protected function pendingUsagesGrouped($pending)
    {
        return $pending->groupBy('variation_id')
            ->map(fn ($rows) => (float) $rows->sum('quantity'));
    }

    protected function latestApprovedQuote(RepairJob $job): ?RepairQuote
    {
        return RepairQuote::query()
            ->where('repair_job_id', $job->getKey())
            ->where('status', RepairQuote::STATUS_APPROVED)
            ->orderByDesc('version_number')
            ->first();
    }

    /** @return array<int, RepairQuoteLine> */
    protected function unbilledQuoteServiceLines(RepairQuote $quote): array
    {
        return RepairQuoteLine::query()
            ->where('quote_id', $quote->getKey())
            ->whereIn('line_type', ['SERVICE', 'FEE'])
            ->orderBy('sort_order')
            ->get()
            ->all();
    }

    protected function validatedSale(RepairJob $job, int $saleTransactionId): Transaction
    {
        $sale = Transaction::query()
            ->where('business_id', $job->business_id)
            ->where('id', $saleTransactionId)
            ->where('type', 'sell')
            ->where('status', 'final')
            ->where('contact_id', $job->contact_id)
            ->where('location_id', $job->location_id)
            ->first();
        if (! $sale) {
            throw new LogicException('Choose a finalized POS sale for the same customer, branch, and location.');
        }

        return $sale;
    }

    protected function formatAmount(float $amount): string
    {
        return number_format($amount, 4, '.', '');
    }

    protected function assertReadableJob(User $user, RepairJob $job): void
    {
        if (! User::can_access_this_location($job->location_id, $user->business_id)
            || (int) $job->business_id !== (int) $user->business_id
            || ! $this->authorizationGate->allowsRead(
                $user,
                self::PERMISSION_VIEW_COST,
                $job->business_id,
                $job->location_id
            )) {
            throw new AuthorizationException();
        }
    }

    protected function authorize(User $user, RepairJob $job): void
    {
        if (! User::can_access_this_location($job->location_id, $user->business_id)
            || (int) $job->business_id !== (int) $user->business_id
            || $job->state === RepairJobStateMachine::STATE_CLOSED
            || ! $this->authorizationGate->allowsWriteLocation(
                $user,
                self::PERMISSION_BILL,
                $job->business_id,
                $job->location_id
            )) {
            throw new AuthorizationException();
        }
    }
}
