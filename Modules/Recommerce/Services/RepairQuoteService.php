<?php

namespace Modules\Recommerce\Services;

use App\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Modules\Recommerce\Entities\RepairJob;
use Modules\Recommerce\Entities\RepairQuote;
use Modules\Recommerce\Entities\RepairQuoteLine;
use Modules\Recommerce\Support\AuthorizationGate;
use Modules\Recommerce\Support\RepairJobStateMachine;
use Throwable;

class RepairQuoteService
{
    public const PERMISSION_MANAGE = 'recommerce.repair.quote.manage';

    public const DECISION_APPROVED = 'APPROVED';
    public const DECISION_DECLINED = 'DECLINED';
    public const DEFAULT_EXPIRY_DAYS = 7;

    public function __construct(
        protected AuthorizationGate $authorizationGate
    )
    {
    }

    /**
     * Create the next immutable quote version for an open customer Repair job.
     *
     * @param array<int, array<string, mixed>> $lines
     */
    public function createDraft(
        User $user,
        RepairJob $job,
        string $commandUuid,
        array $lines,
        ?string $summary = null,
        ?array $taxAssumptions = null,
        ?array $terms = null,
        ?string $currency = null,
        ?string $expiresAt = null
    ): RepairQuote {
        $this->authorize($user, $job);
        if (! $job->isCustomerRepair()) {
            throw new LogicException('Quote versions apply only to customer Repair jobs.');
        }
        $preparedLines = $this->normaliseLines($lines);

        return DB::transaction(function () use ($user, $job, $commandUuid, $preparedLines, $summary, $taxAssumptions, $terms, $currency, $expiresAt): RepairQuote {
            DB::table('business')->where('id', $job->business_id)->lockForUpdate()->first();

            $existing = RepairQuote::query()
                ->where('business_id', $job->business_id)
                ->where('command_uuid', $commandUuid)
                ->first();
            if ($existing) {
                return $existing;
            }

            $lockedJob = RepairJob::query()->whereKey($job->getKey())->lockForUpdate()->first();
            if (! $lockedJob || $lockedJob->state === RepairJobStateMachine::STATE_CLOSED) {
                throw new LogicException('Repair job was not found or is closed.');
            }

            $nextVersion = (int) RepairQuote::query()
                ->where('repair_job_id', $job->getKey())
                ->lockForUpdate()
                ->max('version_number') + 1;

            $totals = $this->totalsFromLines($preparedLines);

            $quote = new RepairQuote([
                'business_id' => $job->business_id,
                'location_id' => $job->location_id,
                'repair_job_id' => $job->id,
                'summary' => $this->summaryText($summary),
                'tax_assumptions_json' => $taxAssumptions,
                'terms_json' => $terms,
                'currency' => $currency !== null ? strtoupper(substr($currency, 0, 12)) : null,
            ]);
            $quote->expires_at = $this->expiryValue($expiresAt);
            $quote->version_number = $nextVersion;
            $quote->status = RepairQuote::STATUS_DRAFT;
            $quote->subtotal_amount = $totals['subtotal'];
            $quote->tax_amount = $totals['tax'];
            $quote->total_amount = $totals['total'];

            try {
                $quote->command_uuid = $commandUuid;
                $quote->quote_uuid = (string) Str::uuid();
            } catch (Throwable $exception) {
                throw new LogicException('Quote identity could not be prepared.', 0, $exception);
            }

            $quote->created_by = $user->getAuthIdentifier();
            $quote->updated_by = $user->getAuthIdentifier();
            $quote->save();

            foreach ($preparedLines as $line) {
                $this->createScopedLine($quote, $line);
            }

            return $quote->fresh(['lines']);
        });
    }

    /** Replace draft lines before the version is sent. */
    public function updateDraft(
        User $user,
        RepairQuote $quote,
        array $lines,
        ?string $summary = null,
        ?array $taxAssumptions = null,
        ?array $terms = null,
        ?string $expiresAt = null
    ): RepairQuote {
        $this->authorize($user, $quote->job);
        $preparedLines = $this->normaliseLines($lines);

        return DB::transaction(function () use ($user, $quote, $preparedLines, $summary, $taxAssumptions, $terms, $expiresAt): RepairQuote {
            DB::table('business')->where('id', $quote->business_id)->lockForUpdate()->first();

            $locked = RepairQuote::query()->whereKey($quote->getKey())->lockForUpdate()->first();
            if (! $locked) {
                throw new LogicException('Quote was not found.');
            }
            if ($locked->status !== RepairQuote::STATUS_DRAFT) {
                throw new LogicException('Sent quote versions are immutable; create a revised version instead.');
            }

            $totals = $this->totalsFromLines($preparedLines);
            $locked->subtotal_amount = $totals['subtotal'];
            $locked->tax_amount = $totals['tax'];
            $locked->total_amount = $totals['total'];
            $locked->summary = $this->summaryText($summary);
            $locked->tax_assumptions_json = $taxAssumptions;
            $locked->terms_json = $terms;
            if ($expiresAt !== null && trim($expiresAt) !== '') {
                $locked->expires_at = $this->expiryValue($expiresAt);
            }
            $locked->updated_by = $user->getAuthIdentifier();
            $locked->save();

            $locked->lines()->delete();
            foreach ($preparedLines as $line) {
                $this->createScopedLine($locked, $line);
            }

            return $locked->fresh(['lines']);
        });
    }

    /** Freeze the version, totals snapshot, and expiry as one immutable record. */
    public function send(User $user, RepairQuote $quote, string $channel): RepairQuote
    {
        $this->authorize($user, $quote->job);

        return DB::transaction(function () use ($user, $quote, $channel): RepairQuote {
            $locked = RepairQuote::query()->whereKey($quote->getKey())->lockForUpdate()->first();
            if (! $locked) {
                throw new LogicException('Quote was not found.');
            }
            if ($locked->status !== RepairQuote::STATUS_DRAFT) {
                throw new LogicException('Only a draft quote version can be sent.');
            }
            if ($locked->lines()->count() === 0) {
                throw new LogicException('A sent quote version requires at least one priced line.');
            }

            $expiresAt = $locked->expires_at ?? now()->addDays(self::DEFAULT_EXPIRY_DAYS);
            $totals = $this->totalsFromLines($locked->lines()->get()->all());
            $locked->status = RepairQuote::STATUS_SENT;
            $locked->subtotal_amount = $totals['subtotal'];
            $locked->tax_amount = $totals['tax'];
            $locked->total_amount = $totals['total'];
            $locked->sent_at = now();
            $locked->sent_channel = $channel !== '' ? mb_substr($channel, 0, 40) : null;
            $locked->sent_by = (int) $user->getAuthIdentifier();
            $locked->expires_at = $expiresAt;
            $locked->updated_by = $user->getAuthIdentifier();
            $locked->save();

            return $locked->fresh(['lines']);
        });
    }

    public function decide(
        User $user,
        RepairQuote $quote,
        string $decision,
        array $evidence = [],
        ?string $note = null
    ): RepairQuote {
        $this->authorize($user, $quote->job);
        if (! in_array($decision, [self::DECISION_APPROVED, self::DECISION_DECLINED], true)) {
            throw new LogicException('Quote decision must be APPROVED or DECLINED.');
        }

        $locked = $this->markExpiredWhenStale($quote, $user);

        if ($locked->status !== RepairQuote::STATUS_SENT) {
            throw new LogicException('Only a sent, unexpired quote version can be decided.');
        }

        return DB::transaction(function () use ($user, $locked, $decision, $evidence, $note): RepairQuote {
            $locked = RepairQuote::query()->whereKey($locked->getKey())->lockForUpdate()->first();
            if (! $locked) {
                throw new LogicException('Quote was not found.');
            }

            $locked->status = $decision;
            $locked->decided_at = now();
            $locked->decided_by = (int) $user->getAuthIdentifier();
            $locked->decision_evidence_json = $evidence;
            $locked->decision_note = $note !== null ? mb_substr(trim($note), 0, 1000) : null;
            $locked->updated_by = $user->getAuthIdentifier();
            $locked->save();

            if ($decision === self::DECISION_APPROVED) {
                // Supersede every older approval/draft once a newer version is
                // approved, so the exact version named in evidence stays unique.
                RepairQuote::query()
                    ->where('repair_job_id', $locked->repair_job_id)
                    ->whereKeyNot($locked->getKey())
                    ->where('version_number', '<', $locked->version_number)
                    ->whereIn('status', [
                        RepairQuote::STATUS_SENT,
                        RepairQuote::STATUS_DRAFT,
                        RepairQuote::STATUS_APPROVED,
                    ])
                    ->update(['status' => RepairQuote::STATUS_SUPERSEDED]);
            }

            return $locked->fresh(['lines']);
        });
    }

    /**
     * Work gate for customer jobs with quote versions.
     *
     * Evidence must name the exact approved version. Any newer sent version
     * makes the approval stale, so scope changes block work until reapproval.
     */
    public function assertWorkAuthorised(RepairJob $job, string $toState, array $evidence): void
    {
        if (! $job->isCustomerRepair()
            || ! in_array($toState, [
                RepairJobStateMachine::STATE_WAITING_PARTS,
                RepairJobStateMachine::STATE_IN_REPAIR,
            ], true)) {
            return;
        }

        $latestVersion = RepairQuote::query()
            ->where('repair_job_id', $job->getKey())
            ->orderByDesc('version_number')
            ->first();
        if (! $latestVersion) {
            return;
        }

        $approvedQuoteId = (int) ($evidence['approved_quote_id'] ?? 0);
        $approved = RepairQuote::query()
            ->where('repair_job_id', $job->getKey())
            ->whereKey($approvedQuoteId)
            ->first();

        if (! $approved || $approved->status !== RepairQuote::STATUS_APPROVED) {
            throw new LogicException('Approve the current quote version before starting repair work.');
        }

        if ($approved->version_number === $latestVersion->version_number) {
            return;
        }

        $newerPending = RepairQuote::query()
            ->where('repair_job_id', $job->getKey())
            ->where('version_number', '>', $approved->version_number)
            ->whereIn('status', [RepairQuote::STATUS_SENT, RepairQuote::STATUS_APPROVED])
            ->exists();
        if ($newerPending) {
            throw new LogicException('Quote scope changed; a revised quote must be approved before work continues.');
        }
    }

    protected function createScopedLine(RepairQuote $quote, array $line): RepairQuoteLine
    {
        $quoteLine = new RepairQuoteLine($line);

        // Quote ownership, location scope, and the persisted line total are
        // service-controlled and are assigned explicitly, not from request data.
        $quoteLine->quote_id = $quote->getKey();
        $quoteLine->business_id = $quote->business_id;
        $quoteLine->location_id = $quote->location_id;
        $quoteLine->line_total_amount = $line['line_total_amount'];

        return tap($quoteLine, fn (RepairQuoteLine $saved) => $saved->save());
    }

    /**
     * Expire a stale sent quote in its own committed transaction so a rejected
     * decision cannot roll the durable status change back.
     */
    protected function markExpiredWhenStale(RepairQuote $quote, User $user): RepairQuote
    {
        return DB::transaction(function () use ($quote, $user): RepairQuote {
            $locked = RepairQuote::query()->whereKey($quote->getKey())->lockForUpdate()->first();
            if (! $locked) {
                throw new LogicException('Quote was not found.');
            }

            if ($locked->status === RepairQuote::STATUS_SENT && $locked->isExpired()) {
                $locked->status = RepairQuote::STATUS_EXPIRED;
                $locked->updated_by = $user->getAuthIdentifier();
                $locked->save();

                return $locked;
            }

            return $locked;
        });
    }

    protected function totalsFromLines(array $lines): array
    {
        $subtotal = 0.0;
        $tax = 0.0;
        foreach ($lines as $line) {
            $subtotal += (float) $line['unit_amount'] * (float) $line['quantity'];
            $tax += (float) $line['tax_amount'];
        }
        $subtotal = round($subtotal, 4);
        $tax = round($tax, 4);

        return [
            'subtotal' => number_format($subtotal, 4, '.', ''),
            'tax' => number_format($tax, 4, '.', ''),
            'total' => number_format($subtotal + $tax, 4, '.', ''),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $lines
     * @return array<int, array<string, mixed>>
     */
    protected function normaliseLines(array $lines): array
    {
        if ($lines === []) {
            throw new LogicException('A quote version requires at least one priced line.');
        }

        $normalised = [];
        foreach (array_values($lines) as $index => $line) {
            $lineType = strtoupper(trim((string) ($line['line_type'] ?? '')));
            if (! in_array($lineType, [RepairQuote::LINE_TYPE_PART, RepairQuote::LINE_TYPE_SERVICE, RepairQuote::LINE_TYPE_FEE], true)) {
                throw new LogicException('Quote line type must be PART, SERVICE, or FEE.');
            }

            $description = trim((string) ($line['description'] ?? ''));
            if ($description === '') {
                throw new LogicException('Quote line description is required.');
            }

            $quantity = (float) ($line['quantity'] ?? 0);
            $unitAmount = (float) ($line['unit_amount'] ?? 0);
            $taxAmount = (float) ($line['tax_amount'] ?? 0);
            if ($quantity <= 0.0) {
                throw new LogicException('Quote line quantity must be greater than zero.');
            }
            if ($unitAmount < 0.0 || $taxAmount < 0.0) {
                throw new LogicException('Quote line amounts cannot be negative.');
            }

            $lineTotal = round(((float) ($line['unit_amount'] ?? 0)) * ((float) ($line['quantity'] ?? 0)) + $taxAmount, 4);

            $normalised[] = [
                'line_type' => $lineType,
                'source_type' => isset($line['source_type']) ? mb_substr((string) $line['source_type'], 0, 40) : null,
                'source_id' => ($line['source_id'] ?? null) === null ? null : (int) $line['source_id'],
                'source_line_id' => ($line['source_line_id'] ?? null) === null ? null : (int) $line['source_line_id'],
                'variation_id' => ($line['variation_id'] ?? null) === null ? null : (int) $line['variation_id'],
                'description' => mb_substr($description, 0, 255),
                'quantity' => number_format($quantity, 4, '.', ''),
                'unit_amount' => number_format($unitAmount, 4, '.', ''),
                'tax_amount' => number_format($taxAmount, 4, '.', ''),
                'line_total_amount' => number_format($lineTotal, 4, '.', ''),
                'sort_order' => $index,
            ];
        }

        return $normalised;
    }

    protected function summaryText(?string $summary): ?string
    {
        $trimmed = trim((string) $summary);

        return $trimmed === '' ? null : mb_substr($trimmed, 0, 320);
    }

    protected function expiryValue(?string $expiresAt): ?string
    {
        if ($expiresAt === null || trim((string) $expiresAt) === '') {
            return null;
        }

        try {
            $expiry = \Illuminate\Support\Carbon::parse($expiresAt);
        } catch (Throwable $exception) {
            throw new LogicException('Quote expiry must be a valid date.', 0, $exception);
        }

        if ($expiry->isPast()) {
            throw new LogicException('Quote expiry must be in the future.');
        }

        return $expiry->format('Y-m-d H:i:s');
    }

    protected function authorize(User $user, RepairJob $job): void
    {
        if (! User::can_access_this_location($job->location_id, $user->business_id)
            || (int) $job->business_id !== (int) $user->business_id
            || $job->state === RepairJobStateMachine::STATE_CLOSED
            || ! $this->authorizationGate->allowsWriteLocation(
                $user,
                self::PERMISSION_MANAGE,
                $job->business_id,
                $job->location_id
            )) {
            throw new AuthorizationException();
        }
    }
}
