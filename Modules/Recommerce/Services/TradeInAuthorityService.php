<?php

namespace Modules\Recommerce\Services;

use App\User;
use Illuminate\Support\Facades\Schema;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use LogicException;
use Modules\Recommerce\Support\AuthorizationGate;
use Modules\Recommerce\Entities\TradeInAuthorityRule;

class TradeInAuthorityService
{
    public function __construct(protected AuthorizationGate $authorizationGate)
    {
    }

    /** @return array{limit: ?float, requires_approval: bool} */
    public function authorityFor(User $user, int $locationId, float $amount): array
    {
        if (! Schema::hasTable('recommerce_trade_in_authority_rules')) {
            return ['limit' => null, 'requires_approval' => false];
        }
        $roles = $this->roleNames($user);
        $rules = TradeInAuthorityRule::query()->where('business_id', $user->business_id)->where('active', true)
            ->where(function ($query) use ($locationId) { $query->whereNull('location_id')->orWhere('location_id', $locationId); })
            ->get()->filter(fn (TradeInAuthorityRule $rule) => $rule->role_name === null || in_array($rule->role_name, $roles, true))
            ->sortByDesc(fn (TradeInAuthorityRule $rule) => ($rule->location_id ? 2 : 0) + ($rule->role_name ? 1 : 0));
        $rule = $rules->first();
        if (! $rule) {
            return ['limit' => null, 'requires_approval' => false];
        }
        $limit = (float) $rule->maximum_without_approval;

        return ['limit' => $limit, 'requires_approval' => $amount > $limit];
    }

    public function configure(User $user, int $locationId, ?string $roleName, $maximum): TradeInAuthorityRule
    {
        if (! Schema::hasTable('recommerce_trade_in_authority_rules')) {
            throw new LogicException('Trade-in authority configuration requires the V2 migration.');
        }
        if (! $this->authorizationGate->allowsWriteLocation($user, TradeInService::PERMISSION_APPROVE, (int) $user->business_id, $locationId)) {
            throw new AuthorizationException('Trade-in authority configuration scope denied.');
        }
        if (! is_numeric($maximum) || (float) $maximum < 0) {
            throw new LogicException('Authority limit must be a non-negative amount.');
        }
        $roleName = $roleName === null || trim($roleName) === '' ? null : mb_substr(trim($roleName), 0, 160);

        return DB::transaction(function () use ($user, $locationId, $roleName, $maximum): TradeInAuthorityRule {
            $prior = TradeInAuthorityRule::query()->where('business_id', $user->business_id)->where('location_id', $locationId)
                ->where('role_name', $roleName)->where('active', true)->lockForUpdate()->get();
            foreach ($prior as $rule) {
                $rule->update(['active' => false]);
            }
            return TradeInAuthorityRule::create([
                'business_id' => $user->business_id, 'location_id' => $locationId, 'role_name' => $roleName,
                'maximum_without_approval' => round((float) $maximum, 4), 'active' => true, 'created_by' => $user->id,
            ]);
        });
    }

    /** @return array<int, string> */
    protected function roleNames(User $user): array
    {
        // Transient policy/test users are not persisted Eloquent models and
        // cannot safely resolve Spatie's relation; they simply receive any
        // branch-wide rule, as intended.
        return $user->exists && method_exists($user, 'getRoleNames')
            ? $user->getRoleNames()->map(fn ($role) => str_replace('#'.$user->business_id, '', (string) $role))->all()
            : [];
    }

}
