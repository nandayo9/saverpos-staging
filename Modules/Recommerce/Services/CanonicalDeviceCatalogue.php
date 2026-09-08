<?php

namespace Modules\Recommerce\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;
use Modules\Recommerce\Services\Intelligence\CategorySchema;
use Modules\Recommerce\Services\Intelligence\RecordStore;

/** Identity and editorial read models only. No stock, price or approval authority. */
final class CanonicalDeviceCatalogue
{
    private array $modelCache = [];
    private array $mappingCache = [];

    public const MODELS = 'recommerce_catalogue_models';
    public const MAPPINGS = 'recommerce_catalogue_mappings';

    public function ready(): bool
    {
        return Schema::hasTable(self::MODELS) && Schema::hasTable(self::MAPPINGS);
    }

    public static function normalized(string $value): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $value)));
    }

    /** Called within the existing governed variant-import transaction. */
    public function registerVariant(int $business, array $data, int $actor, string $reason): void
    {
        if (! $this->ready()) {
            throw new LogicException('Canonical catalogue migration is required before importing variants.');
        }
        foreach (['model_id', 'variant_id', 'brand', 'model_label', 'identity_provenance'] as $field) {
            if (! is_string($data[$field] ?? null) || trim($data[$field]) === '' || mb_strlen($data[$field]) > ($field === 'identity_provenance' ? 500 : ($field === 'brand' ? 120 : 150))) {
                throw new LogicException('Invalid canonical identity field: ' . $field);
            }
        }
        foreach (['model_id', 'variant_id'] as $field) {
            if (! preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,149}$/D', $data[$field])) {
                throw new LogicException('Invalid historical identity.');
            }
        }
        foreach (['family', 'generation'] as $field) {
            if (isset($data[$field]) && (! is_string($data[$field]) || mb_strlen($data[$field]) > 120)) {
                throw new LogicException('Invalid canonical identity field: ' . $field);
            }
        }
        unset($this->modelCache[$business]);
        $this->mappingCache = [];
        $category = $data['category'];
        CategorySchema::fields($category);
        $identity = [$category, $data['brand'], $data['model_label'], $data['generation'] ?? ''];
        $key = hash('sha256', json_encode(array_map([self::class, 'normalized'], $identity), JSON_THROW_ON_ERROR));
        $model = DB::table(self::MODELS)->where('business_id', $business)->where('model_id', $data['model_id'])->first();
        if ($model && $model->identity_key !== $key) {
            throw new LogicException('An existing model identity cannot be reassigned. Review an explicit mapping.');
        }
        $duplicate = DB::table(self::MODELS)->where('business_id', $business)->where('identity_key', $key)->first();
        if ($duplicate && $duplicate->model_id !== $data['model_id']) {
            throw new LogicException('Duplicate model identity. Retain the existing model ID and review the source mapping.');
        }
        if (! $model) {
            $slug = $data['public_slug'] ?? strtolower(str_replace(['_', '.', ':'], '-', $data['model_id']));
            if (! is_string($slug) || ! preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $slug) || strlen($slug) > 160) {
                throw new LogicException('Supply an explicit canonical public_slug.');
            }
            if (DB::table(self::MODELS)->where('business_id', $business)->where('slug', $slug)->exists()) {
                throw new LogicException('Public slug already belongs to another model. Review the mapping.');
            }
            DB::table(self::MODELS)->insert([
                'business_id' => $business, 'model_id' => $data['model_id'], 'slug' => $slug,
                'identity_key' => $key, 'category' => $category, 'brand' => trim($data['brand']),
                'name' => trim($data['model_label']), 'family' => $data['family'] ?? null,
                'generation' => $data['generation'] ?? null, 'synthetic' => true,
                'publication_state' => 'DRAFT', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $spec = $data['specification'];
        $allowed = $category === 'LAPTOP'
            ? ['processor', 'ram', 'storage', 'graphics', 'display']
            : ['storage', 'connectivity', 'colour', 'chip'];
        if (array_diff(array_keys($spec), $allowed)) {
            throw new LogicException('Specification contains fields outside this device category.');
        }
        foreach ($spec as $value) {
            if (! is_string($value) || trim($value) === '' || strlen($value) > 150) {
                throw new LogicException('Invalid exact specification.');
            }
        }
        ksort($spec);
        $specKey = hash('sha256', json_encode(array_map([self::class, 'normalized'], $spec), JSON_THROW_ON_ERROR));
        $mapping = DB::table(self::MAPPINGS)->where('business_id', $business)->where('source', 'VARIANT')->where('source_id', $data['variant_id'])->first();
        if ($mapping && ($mapping->model_id !== $data['model_id'] || $mapping->specification_key !== $specKey || (int) $mapping->native_variation_id !== (int) $data['native_variation_id'])) {
            throw new LogicException('An existing variant cannot be reassigned to another model, specification or native variation.');
        }
        $sameSpec = DB::table(self::MAPPINGS)->where('business_id', $business)->where('model_id', $data['model_id'])->where('specification_key', $specKey)->first();
        if ($sameSpec && $sameSpec->source_id !== $data['variant_id']) {
            throw new LogicException('Duplicate exact specification. Retain the existing variant ID.');
        }
        $native = DB::table(self::MAPPINGS)->where('business_id', $business)->where('source', 'VARIANT')->where('native_variation_id', $data['native_variation_id'])->first();
        if ($native && $native->source_id !== $data['variant_id']) {
            throw new LogicException('Native variation already maps to a different canonical variant.');
        }
        if (! $mapping) {
            DB::table(self::MAPPINGS)->insert([
                'business_id' => $business, 'source' => 'VARIANT', 'source_id' => $data['variant_id'],
                'model_id' => $data['model_id'], 'variant_id' => $data['variant_id'], 'specification_key' => $specKey,
                'specification_json' => json_encode($spec, JSON_THROW_ON_ERROR), 'native_variation_id' => $data['native_variation_id'],
                'provenance' => $data['identity_provenance'], 'actor_id' => $actor, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    /** Explicit publication through the existing SAVERPOS governed import, never inferred from stock. */
    public function publish(int $business, string $modelId, array $data, int $actor, string $reason): array
    {
        if (! $this->ready()) throw new LogicException('Canonical catalogue migration is required.');
        unset($this->modelCache[$business]);
        return DB::transaction(function () use ($business, $modelId, $data, $actor, $reason): array {
            $model = DB::table(self::MODELS)->where('business_id', $business)->where('model_id', $modelId)->lockForUpdate()->first();
            if (! $model) throw new LogicException('Import and review an exact variant before publishing its model.');
            if (($data['expected_revision'] ?? null) !== (int) $model->revision) throw new LogicException('Catalogue revision changed. Reload before publishing.');
            $state = $data['publication_state'] ?? '';
            if (! in_array($state, ['DRAFT', 'PUBLISHED'], true)) throw new LogicException('Invalid catalogue publication state.');
            $content = [];
            foreach (['summary', 'condition_guidance', 'configuration_guidance'] as $field) {
                $value = $data[$field] ?? '';
                if (! is_string($value) || strlen($value) > 3000 || strip_tags($value) !== $value) throw new LogicException('Catalogue content must be bounded plain text.');
                if ($state === 'PUBLISHED' && mb_strlen(trim($value)) < 80) throw new LogicException('Model publication needs useful reviewed ' . $field . '.');
                $content[$field] = trim($value);
            }
            if (! is_string($data['review_reference'] ?? null) || trim($data['review_reference']) === '') throw new LogicException('Editorial provenance is required.');
            if (! is_bool($data['synthetic'] ?? null)) throw new LogicException('Declare whether this is synthetic staging content.');
            // Production eligibility is never conferred by this staging import.
            DB::table(self::MODELS)->where('id', $model->id)->update([
                'publication_state' => $state, 'public_content_json' => json_encode($content, JSON_THROW_ON_ERROR),
                'synthetic' => $data['synthetic'], 'revision' => $model->revision + 1, 'updated_at' => now(),
            ]);
            return app(RecordStore::class)->append($business, 'CATALOGUE_MODEL', $modelId, $data, $state, $actor, $reason);
        });
    }

    public function models(int $business): array
    {
        if (! $this->ready()) return [];
        if (array_key_exists($business, $this->modelCache)) return $this->modelCache[$business];
        return $this->modelCache[$business] = DB::table(self::MODELS)->where('business_id', $business)->where('publication_state', 'PUBLISHED')->orderBy('name')->get()->map(function ($m): array {
            $content = json_decode($m->public_content_json ?? '{}', true) ?: [];
            return [
                'type' => 'model', 'id' => $m->slug, 'canonical_model_id' => $m->model_id,
                'slug' => $m->slug, 'brand' => $m->brand, 'name' => $m->name, 'family' => $m->family,
                'generation' => $m->generation, 'category' => $m->category, 'summary' => $content['summary'] ?? '',
                'condition_guidance' => $content['condition_guidance'] ?? '', 'configuration_guidance' => $content['configuration_guidance'] ?? '',
                'synthetic' => (bool) $m->synthetic, 'indexable' => false,
                'source_version' => (int) $m->revision, 'refreshed_at' => \Carbon\Carbon::parse($m->updated_at)->toAtomString(),
            ];
        })->all();
    }

    public function variantMapping(int $business, int $nativeVariation): ?array
    {
        if (! $this->ready()) return null;
        $key = $business . ':' . $nativeVariation;
        if (array_key_exists($key, $this->mappingCache)) return $this->mappingCache[$key];
        $row = DB::table(self::MAPPINGS)->where('business_id', $business)->where('source', 'VARIANT')->where('native_variation_id', $nativeVariation)->first();
        if (! $row) return $this->mappingCache[$key] = null;
        $latest = app(RecordStore::class)->latest($business, 'VARIANT', $row->variant_id);
        if (($latest['data']['status'] ?? '') !== 'VERIFIED') return null;
        return $this->mappingCache[$key] = (array) $row;
    }

    public function specifications(int $business, string $slug): array
    {
        $model = collect($this->models($business))->firstWhere('slug', $slug);
        if (! $model) return [];
        $rows = DB::table(self::MAPPINGS)->where('business_id', $business)->where('source', 'VARIANT')->where('model_id', $model['canonical_model_id'])->get();
        $out = [];
        foreach ($rows as $row) {
            if (! $this->variantMapping($business, (int) $row->native_variation_id)) continue;
            $spec = json_decode($row->specification_json, true);
            $attributes = [];
            foreach ($spec as $key => $value) $attributes[] = ['key' => $key === 'processor' ? 'cpu' : $key, 'label' => ucfirst($key), 'value' => $value];
            $out[] = ['type' => 'specification', 'id' => $row->variant_id, 'model_id' => $slug, 'model_slug' => $slug,
                'label' => $model['name'] . ' · ' . implode(' · ', array_values($spec)), 'attributes' => $attributes,
                'source_version' => $model['source_version'], 'refreshed_at' => $model['refreshed_at'], 'available_device_count' => 0];
        }
        return $out;
    }
}
