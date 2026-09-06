<?php

namespace Modules\Recommerce\Services;

use App\Product;
use App\ProductVariation;
use App\Unit;
use App\User;
use App\Variation;
use App\Category;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;
use Modules\Recommerce\Entities\TradeInCatalogueOrigin;
use Modules\Recommerce\Entities\TradeInQuickQuote;
use Modules\Recommerce\Entities\TradeInValuation;
use Modules\Recommerce\Support\AuthorizationGate;

/**
 * Controlled catalogue creation for a Trade-In that has no exact SKU.
 *
 * This service creates a permanent native UltimatePOS product/variation before
 * valuation. It never alters a posted purchase, Device Passport or stock row.
 */
class TradeInCatalogueService
{
    public const PERMISSION_CREATE = 'recommerce.tradein.catalogue_create';
    public const PERMISSION_OVERRIDE_DUPLICATE = 'recommerce.tradein.catalogue_override_duplicate';

    public const DUPLICATE_OVERRIDE_REASONS = [
        'MODEL_REVISION' => 'Physically different model revision',
        'SCREEN_CONFIGURATION' => 'Different screen configuration',
        'GPU_CONFIGURATION' => 'Different GPU configuration',
        'KEYBOARD_LAYOUT' => 'Different keyboard or regional layout',
        'PRODUCT_FAMILY' => 'Different product family despite a similar name',
        'OTHER' => 'Other documented difference',
    ];

    public function __construct(
        protected AuthorizationGate $authorizationGate,
        protected ProductTrackingPolicyService $trackingPolicies
    ) {
    }

    /** @return array{exact:?Variation,similar:Collection<int, Variation>,fingerprint:string,specifications:array<string, string>} */
    public function match(int $businessId, array $specifications): array
    {
        $specifications = $this->specifications($specifications);
        $fingerprint = $this->fingerprint($specifications);

        $tnOrigin = Schema::hasTable('recommerce_trade_in_catalogue_origins')
            ? TradeInCatalogueOrigin::query()
                ->where('business_id', $businessId)
                ->where('specification_fingerprint', $fingerprint)
                ->with(['variation.product', 'variation.product_variation'])
                ->first()
            : null;
        if ($tnOrigin && $tnOrigin->variation) {
            return ['exact' => $tnOrigin->variation, 'similar' => collect(), 'fingerprint' => $fingerprint, 'specifications' => $specifications];
        }

        $candidates = Variation::query()->with(['product', 'product_variation'])
            ->whereHas('product', fn ($query) => $query->where('business_id', $businessId))
            ->orderBy('id')->limit(2000)->get();
        $exact = $candidates->first(fn (Variation $variation) => $this->isExact($variation, $specifications));
        $similar = $candidates->filter(fn (Variation $variation) => ! $exact || (int) $variation->id !== (int) $exact->id)
            ->filter(fn (Variation $variation) => $this->isSimilar($variation, $specifications))
            ->take(5)->values()
            ->map(function (Variation $variation) use ($specifications): Variation {
                $variation->setAttribute('catalogue_match', $this->catalogueComparison($variation, $specifications));

                return $variation;
            });

        return ['exact' => $exact, 'similar' => $similar, 'fingerprint' => $fingerprint, 'specifications' => $specifications];
    }

    /** @return array{name:string,sku:string,category:string,specifications:array<string,string>} */
    public function preview(int $businessId, array $specifications): array
    {
        $specifications = $this->specifications($specifications);

        return [
            'name' => $this->displayName($specifications),
            'sku' => $this->uniqueSku($businessId, $this->skuFor($specifications)),
            'category' => 'Laptop',
            'specifications' => $specifications,
        ];
    }

    /** @return array{variation:Variation,created:bool,kind:string} */
    public function createForQuickQuote(User $user, TradeInQuickQuote $quote, bool $allowSimilarOverride = false, ?string $overrideReason = null, ?string $overrideNote = null): array
    {
        $businessId = (int) $user->business_id;
        $locationId = (int) $quote->location_id;
        if ((int) $quote->business_id !== $businessId) {
            throw new AuthorizationException('Trade-In quote scope denied.');
        }
        if (! $this->authorizationGate->allowsWriteLocation($user, self::PERMISSION_CREATE, $businessId, $locationId)) {
            throw new AuthorizationException('You are not authorised to create Trade-In catalogue SKUs.');
        }
        if (! $this->trackingPolicies->availableFor($user, $businessId)) {
            throw new AuthorizationException('Trade-In SKU creation requires authorised serialized-device catalogue access.');
        }

        return DB::transaction(function () use ($user, $quote, $businessId, $locationId, $allowSimilarOverride, $overrideReason, $overrideNote): array {
            $lockedQuote = TradeInQuickQuote::query()->whereKey($quote->id)->lockForUpdate()->firstOrFail();
            if ($lockedQuote->status !== TradeInQuickQuote::STATUS_CONSIDERING || $lockedQuote->isExpired()) {
                throw new LogicException('Only an active Quick Quote can create a Trade-In SKU.');
            }
            if ($lockedQuote->variation_id) {
                $variation = Variation::query()->with('product')->findOrFail($lockedQuote->variation_id);
                return ['variation' => $variation, 'created' => false, 'kind' => 'ALREADY_MATCHED'];
            }

            $match = $this->match($businessId, (array) $lockedQuote->specifications_json);
            if ($match['exact']) {
                $this->assignQuote($lockedQuote, $match['exact']);
                return ['variation' => $match['exact'], 'created' => false, 'kind' => 'REUSED_EXACT'];
            }
            if ($match['similar']->isNotEmpty() && ! $allowSimilarOverride) {
                throw new LogicException('A similar catalogue SKU exists. Use the existing SKU or request duplicate-conflict approval before creating a TN SKU.');
            }
            if ($match['similar']->isNotEmpty() && ! $this->authorizationGate->allowsWriteLocation($user, self::PERMISSION_OVERRIDE_DUPLICATE, $businessId, $locationId)) {
                throw new AuthorizationException('A similar catalogue SKU requires duplicate-conflict approval before a TN SKU can be created.');
            }
            [$overrideReason, $overrideNote] = $this->validatedOverrideReason($match['similar']->isNotEmpty(), $overrideReason, $overrideNote);

            $specifications = $match['specifications'];
            [$product, $variation, $kind] = $this->createNativeCatalogueEntry($user, $businessId, $specifications, (float) $lockedQuote->expected_resale_amount);
            $this->trackingPolicies->syncVariation($product, (int) $variation->id, ProductTrackingPolicyService::INDIVIDUAL_DEVICE, $user);
            TradeInCatalogueOrigin::create([
                'business_id' => $businessId,
                'location_id' => $locationId,
                'product_id' => $product->id,
                'variation_id' => $variation->id,
                'catalogue_origin' => TradeInCatalogueOrigin::ORIGIN_TRADE_IN,
                'specification_fingerprint' => $match['fingerprint'],
                'specifications_json' => $specifications,
                'created_from_quick_quote_id' => $lockedQuote->id,
                'created_by' => $user->id,
                'duplicate_override_reason' => $overrideReason,
                'duplicate_override_note' => $overrideNote,
            ]);
            $this->assignQuote($lockedQuote, $variation);

            return ['variation' => $variation->fresh(['product', 'product_variation']), 'created' => true, 'kind' => $kind];
        });
    }

    public function assignExistingToQuickQuote(User $user, TradeInQuickQuote $quote, int $variationId): Variation
    {
        $businessId = (int) $user->business_id;
        $variation = Variation::query()->with('product')->findOrFail($variationId);
        if (! $variation->product || (int) $variation->product->business_id !== $businessId
            || (int) $quote->business_id !== $businessId
            || ! $this->authorizationGate->allowsWrite($user, TradeInService::PERMISSION_MANAGE, $businessId, (int) $quote->location_id, $variationId)) {
            throw new AuthorizationException('Trade-In catalogue selection denied.');
        }

        return DB::transaction(function () use ($quote, $variation): Variation {
            $locked = TradeInQuickQuote::query()->whereKey($quote->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== TradeInQuickQuote::STATUS_CONSIDERING || $locked->isExpired()) {
                throw new LogicException('Only an active Quick Quote can be matched to a catalogue SKU.');
            }
            $this->assignQuote($locked, $variation);
            return $variation;
        });
    }

    public function attachToValuation(TradeInValuation $valuation): void
    {
        if (! Schema::hasTable('recommerce_trade_in_catalogue_origins')) {
            return;
        }
        TradeInCatalogueOrigin::query()
            ->where('business_id', $valuation->business_id)
            ->where('variation_id', $valuation->variation_id)
            ->whereNull('created_from_trade_in_id')
            ->update(['created_from_trade_in_id' => $valuation->id, 'updated_at' => now()]);
    }

    /** @return array{0:Product,1:Variation,2:string} */
    protected function createNativeCatalogueEntry(User $user, int $businessId, array $specifications, float $expectedResale): array
    {
        $family = $this->familyProduct($businessId, $specifications);
        $sku = $this->uniqueSku($businessId, $this->skuFor($specifications));
        $name = $this->displayName($specifications);
        $purchase = round(max(0, $expectedResale * 0.6), 4);

        if ($family && strtolower((string) $family->type) === 'variable') {
            $productVariation = ProductVariation::query()->create([
                'name' => 'Trade-In configuration', 'product_id' => $family->id, 'is_dummy' => false,
            ]);
            $variation = Variation::query()->create([
                'name' => $this->variationName($specifications), 'product_id' => $family->id,
                'product_variation_id' => $productVariation->id, 'sub_sku' => $sku,
                'default_purchase_price' => $purchase, 'dpp_inc_tax' => $purchase,
                'profit_percent' => 0, 'default_sell_price' => $expectedResale,
                'sell_price_inc_tax' => $expectedResale,
            ]);
            return [$family, $variation, 'NEW_TN_VARIATION'];
        }

        $unitId = Unit::query()->where(function ($query) use ($businessId) {
            $query->where('business_id', $businessId)->orWhereNull('business_id');
        })->orderByRaw('business_id IS NULL')->value('id');
        if (! $unitId) {
            throw new LogicException('A stock unit must exist before a Trade-In SKU can be created.');
        }
        $product = Product::query()->create($this->productAttributes($businessId, $user, $name, $sku, (int) $unitId, $this->laptopCategoryId($businessId)));
        $productVariation = ProductVariation::query()->create([
            'name' => 'DUMMY', 'product_id' => $product->id, 'is_dummy' => true,
        ]);
        $variation = Variation::query()->create([
            'name' => 'DUMMY', 'product_id' => $product->id, 'product_variation_id' => $productVariation->id,
            'sub_sku' => $sku, 'default_purchase_price' => $purchase, 'dpp_inc_tax' => $purchase,
            'profit_percent' => 0, 'default_sell_price' => $expectedResale, 'sell_price_inc_tax' => $expectedResale,
        ]);

        return [$product, $variation, 'NEW_TN_PRODUCT'];
    }

    /** @return array<string, mixed> */
    protected function productAttributes(int $businessId, User $user, string $name, string $sku, int $unitId, ?int $categoryId): array
    {
        $attributes = [
            'business_id' => $businessId, 'name' => $name, 'type' => 'single', 'unit_id' => $unitId,
            'tax_type' => 'exclusive', 'enable_stock' => 1, 'alert_quantity' => 0, 'sku' => $sku,
            'barcode_type' => 'C128', 'enable_sr_no' => 1, 'not_for_selling' => 0, 'created_by' => $user->id,
            'category_id' => $categoryId,
        ];
        return array_filter($attributes, fn ($value, $key) => Schema::hasColumn('products', $key), ARRAY_FILTER_USE_BOTH);
    }

    protected function assignQuote(TradeInQuickQuote $quote, Variation $variation): void
    {
        $quote->update(['product_id' => $variation->product_id, 'variation_id' => $variation->id]);
    }

    protected function familyProduct(int $businessId, array $specifications): ?Product
    {
        $brand = mb_strtolower($specifications['brand']);
        $model = mb_strtolower($specifications['model']);
        return Product::query()->where('business_id', $businessId)
            ->whereRaw('LOWER(name) LIKE ?', ['%'.$brand.'%'])
            ->whereRaw('LOWER(name) LIKE ?', ['%'.$model.'%'])
            ->orderBy('id')->first();
    }

    /** @return array<string, string> */
    protected function specifications(array $input): array
    {
        $values = [];
        foreach (['brand' => 100, 'model' => 160, 'cpu' => 160, 'ram' => 80, 'storage' => 120, 'gpu' => 160, 'display_size' => 40] as $key => $limit) {
            $value = trim((string) ($input[$key] ?? ''));
            if (in_array($key, ['brand', 'model'], true) && $value === '') {
                throw new LogicException(ucfirst($key).' is required to create a Trade-In SKU.');
            }
            if (mb_strlen($value) > $limit) {
                throw new LogicException(ucfirst($key).' is too long for a Trade-In SKU.');
            }
            if ($value !== '') {
                $values[$key] = $value;
            }
        }
        $values['category_code'] = 'LAPTOP';
        return $values;
    }

    protected function fingerprint(array $specifications): string
    {
        return hash('sha256', implode('|', array_map(fn ($key) => $key.'='.$this->normalized($specifications[$key] ?? ''), ['brand', 'model', 'cpu', 'ram', 'storage', 'gpu', 'display_size'])));
    }

    protected function isExact(Variation $variation, array $specifications): bool
    {
        $haystack = $this->variationSearchText($variation);
        foreach (['brand', 'model', 'cpu', 'ram', 'storage', 'gpu', 'display_size'] as $key) {
            $value = $specifications[$key] ?? '';
            if ($value !== '' && ! $this->attributeMatches($haystack, $key, $value)) {
                return false;
            }
        }
        return true;
    }

    protected function isSimilar(Variation $variation, array $specifications): bool
    {
        $haystack = $this->variationSearchText($variation);
        return $this->attributeMatches($haystack, 'brand', $specifications['brand'])
            && $this->attributeMatches($haystack, 'model', $specifications['model']);
    }

    protected function displayName(array $specifications): string
    {
        $variant = $this->displayVariant($specifications);

        return mb_substr(trim(implode(' ', array_filter([$specifications['brand'], $specifications['model'], $variant, 'TN']))), 0, 255);
    }

    protected function variationName(array $specifications): string
    {
        return mb_substr(trim(implode(' ', array_filter([$this->displayVariant($specifications), 'TN']))), 0, 255);
    }

    protected function skuFor(array $specifications): string
    {
        $parts = [
            'TN', $this->brandSkuToken($specifications['brand']), $this->modelSkuToken($specifications['model']),
            $this->cpuSkuToken($specifications['cpu'] ?? null), $this->capacitySkuToken($specifications['ram'] ?? null),
            $this->capacitySkuToken($specifications['storage'] ?? null),
        ];
        $sku = implode('-', array_filter(array_map(function ($part) {
            $part = preg_replace('/[^A-Za-z0-9]+/', '-', strtoupper((string) $part));
            return trim((string) $part, '-');
        }, $parts)));
        return mb_substr(preg_replace('/-+/', '-', $sku) ?: 'TN-DEVICE', 0, 200);
    }

    protected function uniqueSku(int $businessId, string $sku): string
    {
        $exists = Variation::query()->whereHas('product', fn ($query) => $query->where('business_id', $businessId))
            ->where('sub_sku', $sku)->exists()
            || Product::query()->where('business_id', $businessId)->where('sku', $sku)->exists();
        return $exists ? mb_substr($sku, 0, 192).'-'.strtoupper(substr(hash('sha256', $sku), 0, 7)) : $sku;
    }

    protected function normalized(string $value): string
    {
        $normalized = preg_replace('/[^A-Z0-9]+/', '', strtoupper($value)) ?: '';

        return preg_replace('/GENERATION(?=\d)|GEN(?=\d)/', 'G', $normalized) ?: '';
    }

    /** @return array{matching:array<int,string>,review:array<int,array{label:string,incoming:string}>} */
    public function catalogueComparison(Variation $variation, array $specifications): array
    {
        $matching = [];
        $review = [];
        $haystack = $this->variationSearchText($variation);
        foreach (['brand' => 'Brand', 'model' => 'Model', 'cpu' => 'CPU', 'ram' => 'RAM', 'storage' => 'Storage', 'gpu' => 'GPU', 'display_size' => 'Display'] as $key => $label) {
            $value = trim((string) ($specifications[$key] ?? ''));
            if ($value === '') {
                continue;
            }
            if ($this->attributeMatches($haystack, $key, $value)) {
                $matching[] = $label;
            } else {
                $review[] = ['label' => $label, 'incoming' => $value];
            }
        }

        return ['matching' => $matching, 'review' => $review];
    }

    protected function variationSearchText(Variation $variation): string
    {
        return $this->normalized(implode(' ', [optional($variation->product)->name, $variation->name, $variation->sub_sku]));
    }

    protected function attributeMatches(string $haystack, string $key, string $value): bool
    {
        $needle = $this->normalized($value);
        if ($needle !== '' && str_contains($haystack, $needle)) {
            return true;
        }
        if ($key === 'cpu' && preg_match('/(?:I[3579]|M[1234]|RYZEN[3579])/', $needle, $cpuClass)) {
            return str_contains($haystack, $cpuClass[0]);
        }

        return false;
    }

    /** @return array{0:?string,1:?string} */
    protected function validatedOverrideReason(bool $hasSimilar, ?string $reason, ?string $note): array
    {
        if (! $hasSimilar) {
            return [null, null];
        }
        $reason = strtoupper(trim((string) $reason));
        $note = mb_substr(trim((string) $note), 0, 500);
        if (! array_key_exists($reason, self::DUPLICATE_OVERRIDE_REASONS)) {
            throw new LogicException('Choose why this Device requires a separate Trade-In SKU.');
        }
        if ($reason === 'OTHER' && $note === '') {
            throw new LogicException('Describe the documented difference before creating a separate Trade-In SKU.');
        }

        return [$reason, $note !== '' ? $note : null];
    }

    protected function displayVariant(array $specifications): string
    {
        $parts = array_filter([
            $this->cpuDisplay($specifications['cpu'] ?? null),
            $this->capacitySkuToken($specifications['ram'] ?? null),
            $this->capacitySkuToken($specifications['storage'] ?? null),
        ]);
        if (count($parts) >= 2) {
            $cpu = array_shift($parts);

            return trim($cpu.' '.implode('/', $parts));
        }

        return implode(' ', $parts);
    }

    protected function cpuDisplay(?string $value): ?string
    {
        $value = trim((string) $value);
        if (preg_match('/\b(i[3579])\b/i', $value, $match)) {
            return strtolower($match[1]);
        }
        if (preg_match('/\b(M[1234])\b/i', $value, $match)) {
            return strtoupper($match[1]);
        }
        if (preg_match('/\b(Ryzen\s*[3579])\b/i', $value, $match)) {
            return preg_replace('/\s+/', ' ', $match[1]);
        }

        return $value === '' ? null : mb_substr($value, 0, 36);
    }

    protected function brandSkuToken(string $brand): string
    {
        $normalized = $this->normalized($brand);
        $map = ['LENOVO' => 'LEN', 'DELL' => 'DEL', 'HEWLETTPACKARD' => 'HP', 'HP' => 'HP', 'APPLE' => 'APL', 'ASUS' => 'ASU', 'ACER' => 'ACR'];

        return $map[$normalized] ?? mb_substr($normalized, 0, 8);
    }

    protected function modelSkuToken(string $model): string
    {
        $token = $this->normalized($model);
        $token = preg_replace('/^(THINKPAD|LATITUDE|ELITEBOOK)/', '', $token) ?: $token;

        return mb_substr($token, 0, 24);
    }

    protected function cpuSkuToken(?string $cpu): ?string
    {
        return $this->cpuDisplay($cpu);
    }

    protected function capacitySkuToken(?string $capacity): ?string
    {
        $capacity = trim((string) $capacity);
        if (preg_match('/\b(\d+(?:\.\d+)?)\s*(TB|GB)\b/i', $capacity, $match)) {
            return strtoupper($match[1].($match[2] === 'TB' || strtoupper($match[2]) === 'TB' ? 'TB' : ''));
        }

        return $capacity === '' ? null : mb_substr($capacity, 0, 24);
    }

    protected function laptopCategoryId(int $businessId): ?int
    {
        if (! Schema::hasTable('categories')) {
            return null;
        }
        return Category::query()->where('business_id', $businessId)
            ->whereRaw('LOWER(name) = ?', ['laptop'])->value('id');
    }
}
