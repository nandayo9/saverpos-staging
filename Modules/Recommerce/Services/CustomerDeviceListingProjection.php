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

    /**
     * @param array<string, mixed> $filters
     * @return array{records:list<array<string, mixed>>,pagination:array<string, int>}
     */
    public function listings(array $filters): array
    {
        if (! $this->access->enabled()) {
            return ['records' => [], 'pagination' => $this->pagination(1, 12, 0)];
        }

        $filters = $this->filters($filters);
        $query = $this->eligibleQuery();
        $this->applyFilters($query, $filters);
        $total = (int) $query->count();
        $page = min($filters['page'], max(1, (int) ceil($total / $filters['per_page'])));
        $this->applySort($query, $filters['sort']);
        $records = $query->forPage($page, $filters['per_page'])->get()
            ->map(fn (Device $device): ?array => $this->record($device))
            ->filter()
            ->values()
            ->all();

        return [
            'records' => $records,
            'pagination' => $this->pagination($page, $filters['per_page'], $total),
        ];
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
            ->whereHas('currentLocation', fn (Builder $query) => $query->where('business_id', $this->access->businessId()))
            ->whereHas('product', fn (Builder $query) => $query->where('business_id', $this->access->businessId()))
            ->whereHas('variation', fn (Builder $query) => $query->whereColumn('variations.product_id', 'recommerce_devices.product_id'));
    }

    /** @param array<string, mixed> $input @return array{page:int,per_page:int,sort:string,category:?string,brand:?string,model_slug:?string,cpu:?string,ram:?string,storage:?string,branch:?string,min_price:?float,max_price:?float} */
    private function filters(array $input): array
    {
        $choice = fn (string $key, int $limit = 160): ?string => $this->text($input[$key] ?? null, $limit);
        $decimal = function (string $key) use ($input): ?float {
            if (! array_key_exists($key, $input) || $input[$key] === '') {
                return null;
            }
            if (! is_numeric($input[$key]) || (float) $input[$key] < 0) {
                return null;
            }

            return round((float) $input[$key], 2);
        };
        $sort = $choice('sort', 24) ?? 'newest';

        return [
            'page' => max(1, min(100000, (int) ($input['page'] ?? 1))),
            'per_page' => max(1, min(48, (int) ($input['per_page'] ?? 12))),
            'sort' => in_array($sort, ['newest', 'price_low', 'price_high'], true) ? $sort : 'newest',
            'category' => $choice('category', 48),
            'brand' => $choice('brand', 120),
            'model_slug' => $this->slug($input['model_slug'] ?? null),
            'cpu' => $choice('cpu', 160),
            'ram' => $choice('ram', 80),
            'storage' => $choice('storage', 120),
            'branch' => $choice('branch', 160),
            'min_price' => $decimal('min_price'),
            'max_price' => $decimal('max_price'),
        ];
    }

    /** @param Builder<Device> $query @param array<string, mixed> $filters */
    private function applyFilters(Builder $query, array $filters): void
    {
        foreach (['brand', 'cpu', 'ram', 'storage'] as $key) {
            if ($filters[$key] !== null) {
                $query->where('specifications_json->' . $key, $filters[$key]);
            }
        }
        if ($filters['category'] !== null) {
            $query->where('category_code', $filters['category']);
        }
        if ($filters['model_slug'] !== null) {
            $query->where('listing_model_slug', $filters['model_slug']);
        }
        if ($filters['branch'] !== null) {
            $query->whereHas('currentLocation', fn (Builder $location): Builder => $location->where('name', $filters['branch']));
        }
        if ($filters['min_price'] !== null) {
            $query->where('listing_price', '>=', $filters['min_price']);
        }
        if ($filters['max_price'] !== null) {
            $query->where('listing_price', '<=', $filters['max_price']);
        }
    }

    /** @param Builder<Device> $query */
    private function applySort(Builder $query, string $sort): void
    {
        match ($sort) {
            'price_low' => $query->orderBy('listing_price')->orderBy('public_device_id'),
            'price_high' => $query->orderByDesc('listing_price')->orderBy('public_device_id'),
            default => $query->orderByDesc('updated_at')->orderBy('public_device_id'),
        };
    }

    /** @return array{page:int,per_page:int,total:int,total_pages:int} */
    private function pagination(int $page, int $perPage, int $total): array
    {
        return [
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'total_pages' => max(1, (int) ceil($total / $perPage)),
        ];
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
