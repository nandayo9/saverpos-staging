<?php

namespace Modules\Recommerce\Services;

use App\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use LogicException;
use Modules\Recommerce\Entities\SerializationProfile;
use Modules\Recommerce\Support\AuthorizationGate;

class ProductTrackingPolicyService
{
    public function __construct(protected AuthorizationGate $authorizationGate)
    {
    }

    public const INDIVIDUAL_DEVICE = DeviceReceivingProgressService::TRACKING_SERIALIZED_DEVICE;

    public const QUANTITY = DeviceReceivingProgressService::TRACKING_BULK;

    public function available(): bool
    {
        return config('recommerce.enabled', false)
            && Schema::hasTable('recommerce_serialization_profiles')
            && Schema::hasColumn('recommerce_serialization_profiles', 'inventory_tracking_mode');
    }

    public function availableFor($user, int $businessId): bool
    {
        return $this->available()
            && config('recommerce.cohort.allow_approved_product_policies', false)
            && $this->authorizationGate->allowsWriteBusiness($user, 'recommerce.receiving.post', $businessId);
    }

    public function modeForProduct(Product $product): string
    {
        if (! $this->available()) {
            return self::QUANTITY;
        }

        $modes = SerializationProfile::query()
            ->where('business_id', $product->business_id)
            ->where('product_id', $product->id)
            ->pluck('inventory_tracking_mode')
            ->map(fn ($mode) => strtoupper((string) $mode))
            ->filter(fn ($mode) => in_array($mode, [self::INDIVIDUAL_DEVICE, self::QUANTITY], true))
            ->unique()
            ->values();

        return $modes->count() === 1 ? $modes->first() : self::QUANTITY;
    }

    public function modesForVariations(int $businessId, array $variationIds)
    {
        if (! $this->available() || $variationIds === []) {
            return collect();
        }

        return SerializationProfile::query()
            ->where('business_id', $businessId)
            ->whereIn('variation_id', $variationIds)
            ->pluck('inventory_tracking_mode', 'variation_id')
            ->map(fn ($mode) => strtoupper((string) $mode));
    }

    public function isChangeLocked(Product $product): bool
    {
        if (! $product->exists) {
            return false;
        }

        if (DB::table('purchase_lines')->where('product_id', $product->id)->exists()) {
            return true;
        }

        return Schema::hasTable('recommerce_devices')
            && DB::table('recommerce_devices')
                ->where('business_id', $product->business_id)
                ->where('product_id', $product->id)
                ->exists();
    }

    public function sync(Product $product, string $requestedMode, $configuredBy): void
    {
        if (! $this->availableFor($configuredBy, (int) $product->business_id)) {
            return;
        }

        $requestedMode = strtoupper(trim($requestedMode));
        if (! in_array($requestedMode, [self::INDIVIDUAL_DEVICE, self::QUANTITY], true)) {
            throw new InvalidArgumentException('Choose Individual Device or Quantity tracking.');
        }

        $currentMode = $this->modeForProduct($product);
        if ($currentMode !== $requestedMode && $this->isChangeLocked($product)) {
            throw new LogicException('Tracking cannot be changed because this product already has purchase or Device history. Use an authorised inventory correction workflow.');
        }

        $variationIds = $product->variations()->pluck('id');
        if ($variationIds->isEmpty()) {
            throw new LogicException('Create at least one configuration before setting product tracking.');
        }

        foreach ($variationIds as $variationId) {
            SerializationProfile::query()->updateOrCreate(
                [
                    'business_id' => $product->business_id,
                    'variation_id' => $variationId,
                ],
                [
                    'product_id' => $product->id,
                    'mode' => 'TRACKED_REQUIRED',
                    'inventory_tracking_mode' => $requestedMode,
                    'inspection_required' => $requestedMode === self::INDIVIDUAL_DEVICE,
                    'version' => 1,
                    'effective_at' => now(),
                    'configured_by' => (int) $configuredBy->id,
                    'approval_reference' => 'PRODUCT_TRACKING_POLICY',
                ]
            );
        }
    }
}
