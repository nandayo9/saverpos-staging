<?php

namespace Modules\Recommerce\Services;

use App\User;
use App\Variation;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Modules\Recommerce\Entities\RepairJob;
use Modules\Recommerce\Entities\RepairCostEntry;
use Modules\Recommerce\Entities\RepairPartReservation;
use Modules\Recommerce\Entities\RepairPartUsage;
use Modules\Recommerce\Support\AuthorizationGate;

class RepairPartService
{
    public function __construct(
        protected AuthorizationGate $authorizationGate,
        protected UltimatePosStockAdjustmentWriter $stockAdjustmentWriter
    )
    {
    }

    public function reserve(User $user, RepairJob $job, int $variationId, string $commandUuid, string $quantity, ?string $expiresAt = null): RepairPartReservation
    {
        $this->authorize($user, $job, 'recommerce.repair.parts.reserve');
        $requested = (float) $quantity;
        if ($requested <= 0) {
            throw new LogicException('Part reservation quantity must be greater than zero.');
        }

        return DB::transaction(function () use ($user, $job, $variationId, $commandUuid, $quantity, $requested, $expiresAt): RepairPartReservation {
            DB::table('business')->where('id', $job->business_id)->lockForUpdate()->first();
            $existing = RepairPartReservation::query()
                ->where('business_id', $job->business_id)
                ->where('command_uuid', $commandUuid)
                ->first();
            if ($existing) {
                return $existing;
            }

            $lockedJob = RepairJob::query()->whereKey($job->getKey())->lockForUpdate()->first();
            if (! $lockedJob || $lockedJob->state === 'CLOSED') {
                throw new LogicException('Parts cannot be reserved for a closed or missing Repair job.');
            }

            $variation = Variation::query()
                ->whereKey($variationId)
                ->whereHas('product', fn ($query) => $query->where('business_id', $job->business_id))
                ->first();
            if (! $variation) {
                throw new LogicException('Part variation is outside the Repair business scope.');
            }
            $this->authorize($user, $job, 'recommerce.repair.parts.reserve', $variationId);

            $stock = DB::table('variation_location_details')
                ->where('variation_id', $variationId)
                ->where('location_id', $job->location_id)
                ->lockForUpdate()
                ->first();
            if (! $stock) {
                throw new LogicException('Part stock location is unavailable.');
            }

            $reserved = (float) RepairPartReservation::query()
                ->where('business_id', $job->business_id)
                ->where('location_id', $job->location_id)
                ->where('variation_id', $variationId)
                ->whereIn('status', ['RESERVED', 'ISSUED', 'INSTALLED_PENDING_BILLING'])
                ->where(function ($query) {
                    $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
                })
                ->sum('quantity');

            if (((float) $stock->qty_available - $reserved) < $requested) {
                throw new LogicException('Insufficient available part quantity after active reservations.');
            }

            $reservation = new RepairPartReservation([
                'business_id' => $job->business_id,
                'location_id' => $job->location_id,
                'repair_job_id' => $job->getKey(),
                'product_id' => $variation->product_id,
                'variation_id' => $variationId,
                'reserved_at' => now(),
                'expires_at' => $expiresAt,
                'reserved_by' => $user->getAuthIdentifier(),
            ]);
            $reservation->command_uuid = $commandUuid;
            $reservation->quantity = $quantity;
            $reservation->status = 'RESERVED';
            $reservation->save();

            return $reservation;
        });
    }

    public function issue(User $user, RepairPartReservation $reservation, string $commandUuid, string $path): RepairPartUsage
    {
        $job = $reservation->job;
        $this->authorize($user, $job, 'recommerce.repair.parts.use', (int) $reservation->variation_id);
        if (! in_array($path, ['CUSTOMER', 'INTERNAL'], true)) {
            throw new LogicException('Unsupported part consumption path.');
        }
        if (($path === 'CUSTOMER' && $job->job_type !== 'CUSTOMER_REPAIR')
            || ($path === 'INTERNAL' && $job->job_type !== 'INTERNAL_REFURBISHMENT')
        ) {
            throw new LogicException('Part consumption path does not match the Repair job type.');
        }

        return DB::transaction(function () use ($user, $reservation, $commandUuid, $path): RepairPartUsage {
            DB::table('business')->where('id', $reservation->business_id)->lockForUpdate()->first();
            $existing = RepairPartUsage::query()
                ->where('business_id', $reservation->business_id)
                ->where('command_uuid', $commandUuid)
                ->first();
            if ($existing) {
                return $existing;
            }

            $locked = RepairPartReservation::query()->whereKey($reservation->getKey())->lockForUpdate()->first();
            if (! $locked || $locked->status !== 'RESERVED'
                || ($locked->expires_at !== null && $locked->expires_at->isPast())
            ) {
                throw new LogicException('Only a reserved part can be issued.');
            }

            $usage = new RepairPartUsage([
                'business_id' => $locked->business_id,
                'location_id' => $locked->location_id,
                'repair_job_id' => $locked->repair_job_id,
                'reservation_id' => $locked->getKey(),
                'product_id' => $locked->product_id,
                'variation_id' => $locked->variation_id,
                'issued_at' => now(),
                'recorded_by' => $user->getAuthIdentifier(),
            ]);
            $usage->usage_uuid = (string) Str::uuid();
            $usage->command_uuid = $commandUuid;
            $usage->consumption_path = $path;
            $usage->status = 'ISSUED';
            $usage->quantity = $locked->quantity;
            $usage->save();

            $locked->status = 'ISSUED';
            $locked->save();

            return $usage;
        });
    }

    public function install(User $user, RepairPartUsage $usage): RepairPartUsage
    {
        $this->authorize($user, $usage->job, 'recommerce.repair.parts.use', (int) $usage->variation_id);

        return DB::transaction(function () use ($usage): RepairPartUsage {
            $locked = RepairPartUsage::query()->whereKey($usage->getKey())->lockForUpdate()->first();
            if (! $locked || $locked->status !== 'ISSUED') {
                throw new LogicException('Only an issued part can be installed.');
            }
            $locked->status = 'INSTALLED_PENDING_BILLING';
            $locked->installed_at = now();
            $locked->save();

            return $locked;
        });
    }

    public function resolve(User $user, RepairPartUsage $usage, int $sourceTransactionId, int $sourceLineId, string $sourceType): RepairPartUsage
    {
        $this->authorize($user, $usage->job, 'recommerce.repair.parts.resolve', (int) $usage->variation_id);
        $expectedSource = $usage->consumption_path === 'CUSTOMER' ? 'SALE' : 'ADJUSTMENT';
        if ($sourceType !== $expectedSource) {
            throw new LogicException('Part resolution source does not match its consumption path.');
        }

        return DB::transaction(function () use ($usage, $sourceTransactionId, $sourceLineId, $sourceType): RepairPartUsage {
            $locked = RepairPartUsage::query()->whereKey($usage->getKey())->lockForUpdate()->first();
            if (! $locked || $locked->status !== 'INSTALLED_PENDING_BILLING') {
                throw new LogicException('Only an installed pending part can be resolved.');
            }

            $transaction = DB::table('transactions')
                ->where('id', $sourceTransactionId)
                ->where('business_id', $locked->business_id)
                ->where('type', 'sell')
                ->where('status', 'final')
                ->first();
            $line = DB::table('transaction_sell_lines')
                ->where('id', $sourceLineId)
                ->where('transaction_id', $sourceTransactionId)
                ->where('variation_id', $locked->variation_id)
                ->first();
            if (! $transaction || ! $line || (float) $line->quantity < (float) $locked->quantity) {
                throw new LogicException('Customer part resolution requires a finalized POS sale line for the same variation and quantity.');
            }

            $locked->status = 'CONSUMED';
            $locked->source_transaction_id = $sourceTransactionId;
            $locked->source_line_id = $sourceLineId;
            $locked->source_type = $sourceType;
            $locked->resolved_at = now();
            $locked->save();

            $locked->reservation()->update(['status' => 'CONSUMED']);

            return $locked;
        });
    }

    public function consumeInternal(User $user, RepairPartUsage $usage, string $reason): RepairPartUsage
    {
        $this->authorize($user, $usage->job, 'recommerce.repair.parts.resolve', (int) $usage->variation_id);
        if ($usage->consumption_path !== 'INTERNAL' || $usage->status !== 'INSTALLED_PENDING_BILLING') {
            throw new LogicException('Only an installed internal part usage can be consumed.');
        }
        if (trim($reason) === '') {
            throw new LogicException('Internal part consumption requires a reason.');
        }

        return DB::transaction(function () use ($user, $usage, $reason): RepairPartUsage {
            $locked = RepairPartUsage::query()->whereKey($usage->getKey())->lockForUpdate()->first();
            if (! $locked || $locked->status !== 'INSTALLED_PENDING_BILLING' || $locked->consumption_path !== 'INTERNAL') {
                throw new LogicException('Internal part usage is no longer ready for consumption.');
            }

            $result = $this->stockAdjustmentWriter->write($locked, (int) $user->getAuthIdentifier(), $reason);
            $cost = new RepairCostEntry([
                'business_id' => $locked->business_id,
                'repair_job_id' => $locked->repair_job_id,
                'device_id' => $locked->job->device_id,
                'part_usage_id' => $locked->getKey(),
                'cost_category' => 'PART_ACTUAL',
                'amount' => $result['actual_cost'],
                'source_transaction_id' => $result['transaction_id'],
                'source_line_id' => $result['line_id'],
                'reason' => $reason,
                'recorded_by' => $user->getAuthIdentifier(),
                'recorded_at' => now(),
            ]);
            // amount is guarded because it is an immutable, system-derived
            // value; assign it explicitly after the trusted POS result.
            $cost->amount = $result['actual_cost'];
            $cost->recorded_at = now();
            $cost->cost_uuid = (string) Str::uuid();
            $cost->source_key = 'internal-part-usage:'.$locked->getKey();
            $cost->save();

            $locked->status = 'CONSUMED';
            $locked->source_transaction_id = $result['transaction_id'];
            $locked->source_line_id = $result['line_id'];
            $locked->source_type = 'ADJUSTMENT';
            $locked->resolved_at = now();
            $locked->save();
            $locked->reservation()->update(['status' => 'CONSUMED']);

            return $locked->fresh();
        });
    }

    public function release(User $user, RepairPartReservation $reservation, string $reason): RepairPartReservation
    {
        $this->authorize($user, $reservation->job, 'recommerce.repair.parts.reserve', (int) $reservation->variation_id);
        if (trim($reason) === '') {
            throw new LogicException('Part release requires a reason.');
        }

        return DB::transaction(function () use ($reservation, $reason): RepairPartReservation {
            $locked = RepairPartReservation::query()->whereKey($reservation->getKey())->lockForUpdate()->first();
            if (! $locked || $locked->status !== 'RESERVED') {
                throw new LogicException('Only an unused reservation can be released.');
            }
            $locked->status = 'RELEASED';
            $locked->released_at = now();
            $locked->release_reason = $reason;
            $locked->save();

            return $locked;
        });
    }

    protected function authorize(User $user, RepairJob $job, string $permission, ?int $variationId = null): void
    {
        if (! User::can_access_this_location($job->location_id, $user->business_id)
            || (int) $job->business_id !== (int) $user->business_id
            || $job->state === 'CLOSED'
            || ! ($variationId === null
                ? $this->authorizationGate->allowsWriteLocation($user, $permission, $job->business_id, $job->location_id)
                : $this->authorizationGate->allowsWrite($user, $permission, $job->business_id, $job->location_id, $variationId))) {
            throw new AuthorizationException();
        }
    }
}
