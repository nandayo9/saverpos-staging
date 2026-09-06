<?php

namespace Modules\Recommerce\Services;

use Modules\Recommerce\Entities\TradeInValuation;

final class TradeInAcquisitionCommandAccess
{
    public function enabled(): bool
    {
        $token = config('recommerce.tradein_acquisition_command.bearer_token');

        return app()->environment('staging')
            && config('recommerce.enabled') === true
            && config('recommerce.writes_enabled') === true
            && config('recommerce.tradein_acquisition_command.enabled') === true
            && is_string($token)
            && strlen($token) >= 32
            && config('recommerce.tradein_acquisition_command.contract_version') === '1.0'
            && $this->actorUserId() > 0
            && $this->businessId() > 0
            && $this->locationIds() !== []
            && $this->variationIds() !== [];
    }

    public function accepts(?string $authorization): bool
    {
        if (! $this->enabled() || ! is_string($authorization)
            || preg_match('/^Bearer\\s+(.+)$/i', trim($authorization), $matches) !== 1) {
            return false;
        }

        return hash_equals((string) config('recommerce.tradein_acquisition_command.bearer_token'), $matches[1]);
    }

    public function allows(TradeInValuation $valuation): bool
    {
        return $this->enabled()
            && (int) $valuation->business_id === $this->businessId()
            && in_array((int) $valuation->location_id, $this->locationIds(), true)
            && in_array((int) $valuation->variation_id, $this->variationIds(), true);
    }

    public function actorUserId(): int { return (int) config('recommerce.tradein_acquisition_command.actor_user_id'); }
    public function businessId(): int { return (int) config('recommerce.tradein_acquisition_command.business_id'); }

    /** @return list<int> */
    public function locationIds(): array { return $this->ids((array) config('recommerce.tradein_acquisition_command.location_ids', [])); }

    /** @return list<int> */
    public function variationIds(): array { return $this->ids((array) config('recommerce.tradein_acquisition_command.variation_ids', [])); }

    /** @param array<int, mixed> $values @return list<int> */
    private function ids(array $values): array
    {
        return array_values(array_unique(array_filter(array_map('intval', $values), fn (int $id): bool => $id > 0)));
    }
}
