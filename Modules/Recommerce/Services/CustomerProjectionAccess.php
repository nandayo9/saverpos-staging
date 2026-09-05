<?php

namespace Modules\Recommerce\Services;

/**
 * Staging-only access policy for the customer projection API.
 *
 * This is intentionally independent of staff authentication and operational
 * permissions. It grants read access only to the explicitly configured web
 * connector, never to a browser or a user account.
 */
final class CustomerProjectionAccess
{
    public function enabled(): bool
    {
        $token = config('recommerce.customer_projection.bearer_token');

        return app()->environment('staging')
            && config('recommerce.customer_projection.enabled') === true
            && is_string($token)
            && strlen($token) >= 32
            && $this->contractVersion() === '1.0'
            && $this->businessId() > 0
            && $this->locationIds() !== []
            && $this->variationIds() !== [];
    }

    public function accepts(?string $authorization): bool
    {
        if (! $this->enabled() || ! is_string($authorization)) {
            return false;
        }

        if (preg_match('/^Bearer\\s+(.+)$/i', trim($authorization), $matches) !== 1) {
            return false;
        }

        return hash_equals((string) config('recommerce.customer_projection.bearer_token'), $matches[1]);
    }

    public function businessId(): int
    {
        return (int) config('recommerce.customer_projection.business_id');
    }

    /** @return list<int> */
    public function locationIds(): array
    {
        return $this->ids((array) config('recommerce.customer_projection.location_ids', []));
    }

    /** @return list<int> */
    public function variationIds(): array
    {
        return $this->ids((array) config('recommerce.customer_projection.variation_ids', []));
    }

    public function contractVersion(): string
    {
        return (string) config('recommerce.customer_projection.contract_version', '1.0');
    }

    public function currency(): string
    {
        $currency = strtoupper((string) config('recommerce.customer_projection.currency', 'MYR'));

        return preg_match('/^[A-Z]{3}$/', $currency) === 1 ? $currency : 'MYR';
    }

    /** @param array<int, mixed> $values @return list<int> */
    private function ids(array $values): array
    {
        return array_values(array_unique(array_filter(array_map('intval', $values), fn (int $id): bool => $id > 0)));
    }
}
