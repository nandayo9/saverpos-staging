<?php

namespace App\Services;

use App\Transaction;
use App\User;
use App\Utils\Util;
use App\WalkIn;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use LogicException;

class WalkInService
{
    public function __construct(protected Util $util)
    {
    }

    public function capture(User $user, int $locationId): WalkIn
    {
        $this->assertLocationAccess($user, $locationId, 'walkin.create');

        return DB::transaction(function () use ($user, $locationId) {
            $walkIn = WalkIn::create([
                'business_id' => $user->business_id,
                'location_id' => $locationId,
                'arrived_at' => now(),
                'recorded_by' => $user->id,
                'updated_by' => $user->id,
                'status' => WalkIn::STATUS_OPEN,
            ]);
            $this->log($walkIn, 'walk_in_captured');

            return $walkIn;
        });
    }

    public function closeAsNoSale(User $user, int $walkInId, string $reason): WalkIn
    {
        if (! array_key_exists($reason, (array) config('walkin.reasons', []))) {
            throw new LogicException('Select a valid no-sale reason.');
        }

        return DB::transaction(function () use ($user, $walkInId, $reason) {
            $walkIn = WalkIn::query()->lockForUpdate()->findOrFail($walkInId);
            $this->assertWalkInAccess($user, $walkIn, 'walkin.close');
            if ($walkIn->status !== WalkIn::STATUS_OPEN || $walkIn->transaction_id !== null) {
                throw new LogicException('Only an open walk-in can be closed as no sale.');
            }

            $before = clone $walkIn;
            $walkIn->fill([
                'status' => WalkIn::STATUS_NO_SALE,
                'no_sale_reason' => $reason,
                'closed_at' => now(),
                'closed_by' => $user->id,
                'updated_by' => $user->id,
            ])->save();
            $this->log($walkIn, 'walk_in_closed_no_sale', $before);

            return $walkIn;
        });
    }

    public function convert(User $user, int $walkInId, Transaction $transaction): WalkIn
    {
        return DB::transaction(function () use ($user, $walkInId, $transaction) {
            $walkIn = WalkIn::query()->lockForUpdate()->findOrFail($walkInId);
            $this->assertWalkInAccess($user, $walkIn, 'walkin.assign');
            $transaction = Transaction::query()->lockForUpdate()->findOrFail($transaction->id);

            if ($walkIn->status !== WalkIn::STATUS_OPEN || $walkIn->transaction_id !== null) {
                throw new LogicException('Only one open walk-in may be attributed to a sale.');
            }
            if ((int) $transaction->business_id !== (int) $walkIn->business_id
                || (int) $transaction->location_id !== (int) $walkIn->location_id) {
                throw new LogicException('A walk-in can only be attributed to a sale from the same branch.');
            }
            if ($transaction->type !== 'sell' || $transaction->status !== 'final') {
                throw new LogicException('Only a completed POS sale can convert a walk-in.');
            }
            if (WalkIn::query()->where('transaction_id', $transaction->id)->exists()) {
                throw new LogicException('This POS sale is already attributed to a walk-in.');
            }

            $before = clone $walkIn;
            $walkIn->fill([
                'status' => WalkIn::STATUS_CONVERTED,
                'transaction_id' => $transaction->id,
                'converted_at' => now(),
                'closed_at' => now(),
                'closed_by' => $user->id,
                'updated_by' => $user->id,
                'no_sale_reason' => null,
            ])->save();
            $this->log($walkIn, 'walk_in_converted', $before, ['transaction_id' => $transaction->id]);

            return $walkIn;
        });
    }

    /** Keep the historical visit unresolved when its source sale is voided/deleted. */
    public function releaseConversionForTransaction(Transaction $transaction, ?User $actor = null): void
    {
        $walkIn = WalkIn::query()->where('transaction_id', $transaction->id)->lockForUpdate()->first();
        if (! $walkIn) {
            return;
        }

        $before = clone $walkIn;
        $walkIn->fill([
            'status' => WalkIn::STATUS_OPEN,
            'transaction_id' => null,
            'converted_at' => null,
            'closed_at' => null,
            'closed_by' => null,
            'updated_by' => $actor ? $actor->id : null,
            'no_sale_reason' => null,
        ])->save();
        $this->log($walkIn, 'walk_in_conversion_released', $before, ['transaction_id' => $transaction->id]);
    }

    public function summary(int $businessId, $locationId, string $start, string $end): array
    {
        $query = WalkIn::query()->where('walk_ins.business_id', $businessId)
            ->whereBetween('walk_ins.arrived_at', [$start, $end]);
        if ($locationId !== null && $locationId !== '') {
            $query->where('walk_ins.location_id', $locationId);
        }

        $totals = (clone $query)->selectRaw("COUNT(*) as walk_ins, SUM(CASE WHEN status = 'CONVERTED' THEN 1 ELSE 0 END) as converted, SUM(CASE WHEN status = 'NO_SALE' THEN 1 ELSE 0 END) as no_sale, SUM(CASE WHEN status = 'OPEN' THEN 1 ELSE 0 END) as open")->first();
        $revenue = (clone $query)->join('transactions', 'transactions.id', '=', 'walk_ins.transaction_id')
            ->where('walk_ins.status', WalkIn::STATUS_CONVERTED)
            ->where('transactions.type', 'sell')->where('transactions.status', 'final')
            ->sum('transactions.final_total');
        $walkIns = (int) ($totals->walk_ins ?? 0);
        $converted = (int) ($totals->converted ?? 0);

        return [
            'walk_ins' => $walkIns,
            'converted' => $converted,
            'no_sale' => (int) ($totals->no_sale ?? 0),
            'open' => (int) ($totals->open ?? 0),
            'conversion_rate' => $walkIns === 0 ? 0.0 : round(($converted / $walkIns) * 100, 1),
            'revenue' => (float) $revenue,
        ];
    }

    private function assertWalkInAccess(User $user, WalkIn $walkIn, string $permission): void
    {
        if ((int) $walkIn->business_id !== (int) $user->business_id) {
            throw new AuthorizationException();
        }
        $this->assertLocationAccess($user, (int) $walkIn->location_id, $permission);
    }

    private function assertLocationAccess(User $user, int $locationId, string $permission): void
    {
        if (! User::can_access_this_location($locationId, $user->business_id) || ! $user->can($permission)) {
            throw new AuthorizationException();
        }
    }

    private function log(WalkIn $walkIn, string $action, ?WalkIn $before = null, array $properties = []): void
    {
        $this->util->activityLog($walkIn, $action, $before, $properties, true, $walkIn->business_id);
    }
}
