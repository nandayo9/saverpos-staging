<?php

namespace Modules\Recommerce\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Modules\Recommerce\Entities\Device;

/**
 * Allowlisted, staging-only pre-sale listing projection.
 *
 * It is a read model over authoritative Device, branch, variation and
 * inspection facts. It does not write stock, lifecycle, prices, or evidence.
 */
final class CustomerDeviceListingProjection
{
    public function __construct(private CustomerProjectionAccess $access)
    {
    }

    /** @return list<array<string, mixed>> */
    public function models(): array
    {
        return $this->eligibleRecords()
            ->groupBy(fn (array $record): string => $record['model']['id'])
            ->map(function (Collection $records): array {
                $model = $records->first()['model'];
                $model['available_device_count'] = $records->count();
                $model['source_version'] = $records->max('source_version');
                $model['refreshed_at'] = $records->max('refreshed_at');

                return $model;
            })
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all();
    }

    /** @return array<string, mixed>|null */
    public function model(string $slug): ?array
    {
        return collect($this->models())->first(fn (array $model): bool => $model['slug'] === $slug);
    }

    /** @return list<array<string, mixed>> */
    public function specifications(string $modelSlug): array
    {
        return $this->eligibleRecords()
            ->filter(fn (array $record): bool => $record['model']['slug'] === $modelSlug)
            ->groupBy(fn (array $record): string => $record['specification']['id'])
            ->map(function (Collection $records): array {
                $specification = $records->first()['specification'];
                $specification['available_device_count'] = $records->count();
                $specification['source_version'] = $records->max('source_version');
                $specification['refreshed_at'] = $records->max('refreshed_at');

                return $specification;
            })
            ->sortBy('label', SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all();
    }

    /** @return array<string, mixed>|null */
    public function specification(string $publicId): ?array
    {
        return collect($this->eligibleRecords())->first(
            fn (array $record): bool => $record['specification']['id'] === $publicId
        )['specification'] ?? null;
    }

    /** @return list<array<string, mixed>> */
    public function devices(string $specificationId): array
    {
        return $this->eligibleRecords()
            ->filter(fn (array $record): bool => $record['specification']['id'] === $specificationId)
            ->sortBy('price.amount_minor')
            ->values()
            ->all();
    }

    /** @return array<string, mixed>|null */
    public function device(string $publicDeviceId): ?array
    {
        return $this->eligibleRecords()->first(
            fn (array $record): bool => $record['public_device_id'] === $publicDeviceId
        );
    }

    /** @return Collection<int, array<string, mixed>> */
    private function eligibleRecords(): Collection
    {
        if (! $this->access->enabled()) {
            return collect();
        }

        return $this->eligibleQuery()->get()
            ->map(fn (Device $device): ?array => $this->record($device))
            ->filter()
            ->values();
    }

    /** @return Builder<Device> */
    private function eligibleQuery(): Builder
    {
        return Device::query()
            ->with(['product.brand', 'variation', 'currentLocation', 'inspection'])
            ->where('business_id', $this->access->businessId())
            ->whereIn('current_location_id', $this->access->locationIds())
            ->whereIn('variation_id', $this->access->variationIds())
            ->where('lifecycle_state', 'AVAILABLE')
            ->where('custody_kind', 'LOCATION')
            ->where('stock_participation', 'ON_HAND')
            ->where('transfer_state', 'NONE')
            ->whereNull('sold_at')
            ->where('listing_publication_state', 'PUBLISHED')
            ->whereNotNull('listing_price')
            ->where('listing_price', '>', 0)
            ->whereNotNull('public_device_id')
            ->whereNotNull('listing_model_slug')
            ->whereNotNull('listing_specification_id')
            ->whereHas('currentLocation', fn (Builder $query) => $query->where('business_id', $this->access->businessId()));
    }

    /** @return array<string, mixed>|null */
    private function record(Device $device): ?array
    {
        if (! $device->product || ! $device->variation || ! $device->currentLocation
            || (int) $device->variation->product_id !== (int) $device->product_id) {
            return null;
        }

        $specifications = is_array($device->specifications_json) ? $device->specifications_json : [];
        $brand = $this->text($specifications['brand'] ?? optional($device->product->brand)->name);
        $modelName = $this->text($specifications['model'] ?? $device->product->name);
        $modelSlug = $this->slug($device->listing_model_slug);
        $specificationId = $this->text($device->listing_specification_id, 96);
        $publicDeviceId = $this->text($device->public_device_id, 64);

        if ($brand === null || $modelName === null || $modelSlug === null || $specificationId === null || $publicDeviceId === null) {
            return null;
        }

        $attributes = [];
        foreach (['cpu' => 'CPU', 'ram' => 'RAM', 'storage' => 'Storage', 'gpu' => 'GPU', 'display' => 'Display'] as $key => $label) {
            $value = $this->text($specifications[$key] ?? null);
            if ($value !== null) {
                $attributes[] = ['key' => $key, 'label' => $label, 'value' => $value];
            }
        }

        $generation = $this->text($specifications['generation'] ?? $specifications['variant'] ?? null);
        $category = $this->text($specifications['device_type'] ?? $device->category_code) ?? 'DEVICE';
        $labelParts = array_filter([$modelName, ...array_column($attributes, 'value')]);
        $priceMinor = (int) round(((float) $device->listing_price) * 100);
        $currency = $this->currency($device->listing_currency);
        $inspectionRecorded = optional($device->inspection)->status === DeviceInspectionService::STATUS_PASSED;
        $refreshedAt = optional($device->updated_at)->toAtomString() ?: now()->toAtomString();

        return [
            'public_device_id' => $publicDeviceId,
            'model' => [
                'type' => 'model',
                'id' => $modelSlug,
                'slug' => $modelSlug,
                'brand' => $brand,
                'name' => $modelName,
                'generation' => $generation,
                'category' => $category,
                'summary' => null,
            ],
            'specification' => [
                'type' => 'specification',
                'id' => $specificationId,
                'model_id' => $modelSlug,
                'model_slug' => $modelSlug,
                'label' => implode(' · ', $labelParts),
                'attributes' => $attributes,
            ],
            'price' => [
                'currency' => $currency,
                'amount_minor' => $priceMinor,
                'formatted' => $currency . ' ' . number_format($priceMinor / 100, 2),
            ],
            'branch' => [
                'name' => $this->text($device->currentLocation->name) ?? 'Branch information unavailable',
            ],
            'availability' => [
                'state' => 'AVAILABLE',
                'checked_at' => $refreshedAt,
                'transaction_enabled' => false,
            ],
            'passport' => [
                'inspection' => ['status' => $inspectionRecorded ? 'RECORDED' : 'NOT_RECORDED'],
                'condition' => ['status' => 'NOT_RECORDED'],
                'battery' => ['status' => 'NOT_RECORDED'],
                'defects' => [],
                'refurbishment' => [],
                'warranty' => ['status' => 'NOT_RECORDED'],
            ],
            'source_version' => max(1, (int) $device->lock_version),
            'refreshed_at' => $refreshedAt,
        ];
    }

    private function currency(mixed $value): string
    {
        $currency = strtoupper((string) $value);

        return preg_match('/^[A-Z]{3}$/', $currency) === 1 ? $currency : $this->access->currency();
    }

    private function slug(mixed $value): ?string
    {
        $value = strtolower(trim((string) $value));

        return preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $value) === 1 ? $value : null;
    }

    private function text(mixed $value, int $limit = 160): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }
        $value = trim((string) $value);
        if ($value === '' || mb_strlen($value) > $limit || preg_match('/[[:cntrl:]]/', $value) === 1) {
            return null;
        }

        return $value;
    }
}
