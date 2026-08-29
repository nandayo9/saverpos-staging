<?php

namespace Modules\Recommerce\Support;

/**
 * Deny-by-default cohort boundary for Recommerce read and write paths.
 *
 * Read paths require the enabled module and matching business/location/
 * variation scope. Write paths additionally require the write switch. Empty
 * or incomplete cohort configuration always denies access.
 */
class CohortPolicy
{
    public function isEnabled(): bool
    {
        return config('recommerce.enabled', false) === true;
    }

    public function allowsBusiness($businessId): bool
    {
        return $this->isEnabled()
            && $this->writesEnabled()
            && $this->matchesConfiguredId('business_id', $businessId);
    }

    public function allowsLocation($businessId, $locationId): bool
    {
        return $this->allowsBusiness($businessId)
            && $this->matchesConfiguredLocation($locationId);
    }

    public function allowsReadLocation($businessId, $locationId): bool
    {
        return $this->isEnabled()
            && $this->matchesConfiguredId('business_id', $businessId)
            && $this->matchesConfiguredLocation($locationId);
    }

    public function allowsReadVariation($businessId, $locationId, $variationId): bool
    {
        if (! $this->allowsReadLocation($businessId, $locationId)) {
            return false;
        }

        return $this->variationIsConfigured($variationId);
    }

    public function allowsVariation($businessId, $locationId, $variationId): bool
    {
        if (! $this->allowsLocation($businessId, $locationId)) {
            return false;
        }

        return $this->variationIsConfigured($variationId);
    }

    protected function writesEnabled(): bool
    {
        return config('recommerce.writes_enabled', false) === true;
    }

    protected function matchesConfiguredId(string $key, $actualId): bool
    {
        $configuredId = config('recommerce.cohort.' . $key);

        if ($configuredId === null || $configuredId === '' || $actualId === null || $actualId === '') {
            return false;
        }

        return (string) $configuredId === (string) $actualId;
    }

    protected function matchesConfiguredLocation($actualId): bool
    {
        $configured = config('recommerce.cohort.location_ids', []);
        if (! is_array($configured) || $configured === []) {
            return $this->matchesConfiguredId('location_id', $actualId);
        }

        foreach ($configured as $locationId) {
            if ((string) $locationId === (string) $actualId) {
                return true;
            }
        }

        // Keep the original one-branch pilot contract intact for callers
        // which explicitly configure the legacy location value, even if an
        // inherited config instance also carries an unrelated multi-location
        // list (notably isolated test and command contexts).
        return $this->matchesConfiguredId('location_id', $actualId);
    }

    protected function variationIsConfigured($variationId): bool
    {
        $variationIds = config('recommerce.cohort.variation_ids', []);

        if (! is_array($variationIds) || $variationIds === []) {
            return false;
        }

        foreach ($variationIds as $configuredVariationId) {
            if ((string) $configuredVariationId === (string) $variationId) {
                return true;
            }
        }

        return false;
    }
}
