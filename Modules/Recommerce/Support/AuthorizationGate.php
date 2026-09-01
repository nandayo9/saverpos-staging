<?php

namespace Modules\Recommerce\Support;

/**
 * Permission and tenant/location intersection for Recommerce routes/services.
 *
 * Missing users, permissions, or scope configuration always deny access.
 */
class AuthorizationGate
{
    public function __construct(protected CohortPolicy $cohortPolicy)
    {
    }

    public function allowsRead($user, string $permission, $businessId, $locationId, $variationId = null): bool
    {
        if (! $this->hasPermission($user, $permission)) {
            return false;
        }

        if ($variationId === null) {
            return $this->cohortPolicy->allowsReadLocation($businessId, $locationId);
        }

        return $this->cohortPolicy->allowsReadVariation($businessId, $locationId, $variationId);
    }

    public function allowsWrite($user, string $permission, $businessId, $locationId, $variationId): bool
    {
        return $this->hasPermission($user, $permission)
            && $this->cohortPolicy->allowsVariation($businessId, $locationId, $variationId);
    }

    public function allowsWriteLocation($user, string $permission, $businessId, $locationId): bool
    {
        return $this->hasPermission($user, $permission)
            && $this->cohortPolicy->allowsLocation($businessId, $locationId);
    }

    public function allowsWriteBusiness($user, string $permission, $businessId): bool
    {
        return $this->hasPermission($user, $permission)
            && $this->cohortPolicy->allowsBusiness($businessId);
    }

    protected function hasPermission($user, string $permission): bool
    {
        $permissions = config('recommerce.permissions', []);

        if (! is_array($permissions) || ! in_array($permission, $permissions, true)) {
            return false;
        }

        return is_object($user)
            && method_exists($user, 'can')
            && $user->can($permission) === true;
    }
}
