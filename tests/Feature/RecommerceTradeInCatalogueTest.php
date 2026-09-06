<?php

namespace Tests\Feature;

use App\Product;
use App\ProductVariation;
use App\Unit;
use App\User;
use App\Variation;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;
use Modules\Recommerce\Entities\TradeInCatalogueOrigin;
use Modules\Recommerce\Entities\TradeInQuickQuote;
use Modules\Recommerce\Services\ProductTrackingPolicyService;
use Modules\Recommerce\Services\TradeInCatalogueService;
use Modules\Recommerce\Support\AuthorizationGate;
use Modules\Recommerce\Support\CohortPolicy;
use Tests\TestCase;

class RecommerceTradeInCatalogueTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:',
            'recommerce.enabled' => true, 'recommerce.writes_enabled' => true,
            'recommerce.cohort.business_id' => 7, 'recommerce.cohort.location_id' => 101,
            'recommerce.cohort.location_ids' => [101], 'recommerce.cohort.variation_ids' => [303],
            'recommerce.cohort.allow_approved_product_policies' => true,
        ]);
        DB::purge('sqlite');
        $schema = Schema::connection('sqlite');
        $schema->create('business', fn (Blueprint $t) => $t->unsignedInteger('id')->primary());
        $schema->create('business_locations', function (Blueprint $t) { $t->unsignedInteger('id')->primary(); $t->unsignedInteger('business_id'); });
        $schema->create('users', function (Blueprint $t) { $t->unsignedInteger('id')->primary(); $t->unsignedInteger('business_id'); });
        $schema->create('units', function (Blueprint $t) { $t->increments('id'); $t->unsignedInteger('business_id')->nullable(); $t->string('actual_name')->nullable(); $t->string('short_name')->nullable(); $t->boolean('allow_decimal')->default(false); $t->softDeletes(); $t->timestamps(); });
        $schema->create('products', function (Blueprint $t) { $t->increments('id'); $t->unsignedInteger('business_id'); $t->string('name'); $t->string('type'); $t->unsignedInteger('unit_id'); $t->string('tax_type')->default('exclusive'); $t->boolean('enable_stock')->default(false); $t->decimal('alert_quantity', 22, 4)->default(0); $t->string('sku'); $t->string('barcode_type')->default('C128'); $t->boolean('enable_sr_no')->default(false); $t->boolean('not_for_selling')->default(false); $t->unsignedInteger('created_by'); $t->timestamps(); });
        $schema->create('product_variations', function (Blueprint $t) { $t->increments('id'); $t->string('name'); $t->unsignedInteger('product_id'); $t->boolean('is_dummy')->default(true); $t->timestamps(); });
        $schema->create('variations', function (Blueprint $t) { $t->increments('id'); $t->string('name'); $t->unsignedInteger('product_id'); $t->string('sub_sku')->nullable(); $t->unsignedInteger('product_variation_id'); $t->decimal('default_purchase_price', 22, 4)->nullable(); $t->decimal('dpp_inc_tax', 22, 4)->default(0); $t->decimal('profit_percent', 22, 4)->default(0); $t->decimal('default_sell_price', 22, 4)->nullable(); $t->decimal('sell_price_inc_tax', 22, 4)->nullable(); $t->softDeletes(); $t->timestamps(); });
        $schema->create('recommerce_serialization_profiles', function (Blueprint $t) { $t->increments('id'); $t->unsignedInteger('business_id'); $t->unsignedInteger('product_id'); $t->unsignedInteger('variation_id'); $t->string('mode'); $t->string('inventory_tracking_mode')->nullable(); $t->boolean('inspection_required')->default(true); $t->unsignedInteger('version')->default(1); $t->timestamp('effective_at')->nullable(); $t->unsignedInteger('configured_by')->nullable(); $t->string('approval_reference')->nullable(); $t->timestamps(); $t->unique(['business_id', 'variation_id']); });
        $schema->create('recommerce_trade_in_quick_quotes', function (Blueprint $t) { $t->bigIncrements('id'); $t->unsignedInteger('business_id'); $t->unsignedInteger('location_id'); $t->unsignedInteger('product_id')->nullable(); $t->unsignedInteger('variation_id')->nullable(); $t->string('status'); $t->json('specifications_json'); $t->decimal('expected_resale_amount', 22, 4); $t->dateTime('expires_at'); $t->timestamps(); });
        $schema->create('recommerce_trade_in_valuations', function (Blueprint $t) { $t->bigIncrements('id'); });
        (require base_path('Modules/Recommerce/Database/Migrations/2026_09_04_000003_create_recommerce_trade_in_catalogue_origins.php'))->up();
        (require base_path('Modules/Recommerce/Database/Migrations/2026_09_04_000005_add_duplicate_override_audit_to_trade_in_catalogue_origins.php'))->up();
        DB::table('business')->insert(['id' => 7]);
        DB::table('business_locations')->insert(['id' => 101, 'business_id' => 7]);
        DB::table('users')->insert(['id' => 900, 'business_id' => 7]);
        Unit::query()->create(['business_id' => 7, 'actual_name' => 'Pieces', 'short_name' => 'pcs']);
    }

    protected function tearDown(): void
    {
        DB::disconnect('sqlite');
        parent::tearDown();
    }

    public function test_an_unlisted_quote_creates_one_permanent_tracked_tn_product_and_reuses_it_later(): void
    {
        $first = $this->catalogue()->createForQuickQuote($this->user(), $this->quote());

        $this->assertTrue($first['created']);
        $this->assertSame('NEW_TN_PRODUCT', $first['kind']);
        $this->assertSame('Lenovo ThinkPad T14 Gen 2 i5 16/512 TN', $first['variation']->product->name);
        $this->assertSame('TN-LEN-T14G2-I5-16-512', $first['variation']->sub_sku);
        $this->assertSame(1, TradeInCatalogueOrigin::query()->where('catalogue_origin', 'TRADE_IN')->count());
        $this->assertSame('SERIALIZED_DEVICE', DB::table('recommerce_serialization_profiles')->value('inventory_tracking_mode'));
        $this->assertNotNull($this->quote()->fresh()->variation_id);

        $again = $this->catalogue()->createForQuickQuote($this->user(), $this->quote(['id' => 2]));
        $this->assertFalse($again['created']);
        $this->assertSame('REUSED_EXACT', $again['kind']);
        $this->assertSame($first['variation']->id, $again['variation']->id);
        $this->assertSame(1, TradeInCatalogueOrigin::query()->count());
    }

    public function test_exact_standard_sku_wins_and_is_never_renamed_to_tn(): void
    {
        $standard = $this->standardVariation('Lenovo ThinkPad T14 Gen 2 Intel Core i5 16GB 512GB SSD', 'LEN-T14G2-I5-16-512');
        $result = $this->catalogue()->createForQuickQuote($this->user(), $this->quote());

        $this->assertFalse($result['created']);
        $this->assertSame('REUSED_EXACT', $result['kind']);
        $this->assertSame($standard->id, $result['variation']->id);
        $this->assertStringNotContainsString('TN', $standard->fresh()->product->name);
        $this->assertSame(0, TradeInCatalogueOrigin::query()->count());
    }

    public function test_similar_match_warns_then_authorised_override_creates_tn_variation_under_variable_family(): void
    {
        $family = Product::query()->create(['business_id' => 7, 'name' => 'Lenovo ThinkPad T14 Gen 2', 'type' => 'variable', 'unit_id' => 1, 'tax_type' => 'exclusive', 'enable_stock' => 1, 'alert_quantity' => 0, 'sku' => 'LEN-T14G2', 'barcode_type' => 'C128', 'created_by' => 900]);
        $existing = $this->variationFor($family, 'Intel Core i5 8GB 256GB SSD', 'LEN-T14G2-I5-8-256');
        try {
            $this->catalogue()->createForQuickQuote($this->user(), $this->quote());
            $this->fail('A likely duplicate must be reviewed before TN creation.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('similar catalogue SKU', $exception->getMessage());
        }

        try {
            $this->catalogue()->createForQuickQuote($this->user(), $this->quote(['id' => 2]), true);
            $this->fail('A duplicate override must record its reason.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('Choose why', $exception->getMessage());
        }

        $result = $this->catalogue()->createForQuickQuote($this->user(), $this->quote(['id' => 3]), true, 'SCREEN_CONFIGURATION');
        $this->assertTrue($result['created']);
        $this->assertSame('NEW_TN_VARIATION', $result['kind']);
        $this->assertSame($family->id, $result['variation']->product_id);
        $this->assertStringContainsString('TN', $result['variation']->name);
        $this->assertSame('Lenovo ThinkPad T14 Gen 2', $family->fresh()->name);
        $this->assertSame('Intel Core i5 8GB 256GB SSD', $existing->fresh()->name);
        $this->assertNull($existing->fresh()->tradeInCatalogueOrigin);
        $this->assertSame('SCREEN_CONFIGURATION', TradeInCatalogueOrigin::query()->value('duplicate_override_reason'));
    }

    public function test_condition_battery_and_serial_differences_reuse_the_same_sku_defining_specification(): void
    {
        $first = $this->catalogue()->createForQuickQuote($this->user(), $this->quote());
        $repeat = $this->catalogue()->createForQuickQuote($this->user(), $this->quote([
            'id' => 2,
            'specifications_json' => ['brand' => 'Lenovo', 'model' => 'ThinkPad T14 Gen 2', 'cpu' => 'Intel Core i5', 'ram' => '16GB', 'storage' => '512GB SSD'],
        ]));

        $this->assertFalse($repeat['created']);
        $this->assertSame($first['variation']->id, $repeat['variation']->id);
        $this->assertSame(1, TradeInCatalogueOrigin::query()->count());
    }

    public function test_duplicate_override_requires_its_own_permission_and_an_other_reason_needs_a_note(): void
    {
        $family = Product::query()->create(['business_id' => 7, 'name' => 'Lenovo ThinkPad T14 Gen 2', 'type' => 'variable', 'unit_id' => 1, 'tax_type' => 'exclusive', 'enable_stock' => 1, 'alert_quantity' => 0, 'sku' => 'LEN-T14G2', 'barcode_type' => 'C128', 'created_by' => 900]);
        $this->variationFor($family, 'Intel Core i5 8GB 256GB SSD', 'LEN-T14G2-I5-8-256');
        $withoutOverride = array_values(array_diff(config('recommerce.permissions'), [TradeInCatalogueService::PERMISSION_OVERRIDE_DUPLICATE]));

        try {
            $this->catalogue()->createForQuickQuote($this->user($withoutOverride), $this->quote(), true, 'SCREEN_CONFIGURATION');
            $this->fail('Duplicate override must have its own permission.');
        } catch (AuthorizationException $exception) {
            $this->assertStringContainsString('duplicate-conflict approval', $exception->getMessage());
        }
        try {
            $this->catalogue()->createForQuickQuote($this->user(), $this->quote(['id' => 2]), true, 'OTHER');
            $this->fail('Other duplicate overrides need a factual note.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('Describe the documented difference', $exception->getMessage());
        }
    }

    public function test_unauthorised_actor_cannot_invoke_tn_creation(): void
    {
        $this->expectException(AuthorizationException::class);
        $this->catalogue()->createForQuickQuote($this->user(['recommerce.tradein.view', 'recommerce.tradein.manage', 'recommerce.receiving.post']), $this->quote());
    }

    protected function catalogue(): TradeInCatalogueService
    {
        return new TradeInCatalogueService(new AuthorizationGate(new CohortPolicy()), new ProductTrackingPolicyService(new AuthorizationGate(new CohortPolicy())));
    }

    /** @param array<string, mixed> $overrides */
    protected function quote(array $overrides = []): TradeInQuickQuote
    {
        $attributes = array_replace([
            'id' => 1, 'business_id' => 7, 'location_id' => 101, 'status' => TradeInQuickQuote::STATUS_CONSIDERING,
            'specifications_json' => ['brand' => 'Lenovo', 'model' => 'ThinkPad T14 Gen 2', 'cpu' => 'Intel Core i5', 'ram' => '16GB', 'storage' => '512GB SSD'],
            'expected_resale_amount' => 3200, 'expires_at' => now()->addDay(), 'created_at' => now(), 'updated_at' => now(),
        ], $overrides);
        $attributes['specifications_json'] = json_encode($attributes['specifications_json']);
        DB::table('recommerce_trade_in_quick_quotes')->updateOrInsert(['id' => $attributes['id']], $attributes);
        return TradeInQuickQuote::query()->findOrFail($attributes['id']);
    }

    protected function standardVariation(string $name, string $sku): Variation
    {
        $product = Product::query()->create(['business_id' => 7, 'name' => $name, 'type' => 'single', 'unit_id' => 1, 'tax_type' => 'exclusive', 'enable_stock' => 1, 'alert_quantity' => 0, 'sku' => $sku, 'barcode_type' => 'C128', 'created_by' => 900]);
        return $this->variationFor($product, 'DUMMY', $sku);
    }

    protected function variationFor(Product $product, string $name, string $sku): Variation
    {
        $group = ProductVariation::query()->create(['name' => 'DUMMY', 'product_id' => $product->id, 'is_dummy' => true]);
        return Variation::query()->create(['name' => $name, 'product_id' => $product->id, 'product_variation_id' => $group->id, 'sub_sku' => $sku, 'default_purchase_price' => 1000, 'dpp_inc_tax' => 1000, 'profit_percent' => 0, 'default_sell_price' => 2000, 'sell_price_inc_tax' => 2000]);
    }

    /** @param array<int, string>|null $allowed */
    protected function user(?array $allowed = null): User
    {
        $allowed = $allowed ?: config('recommerce.permissions');
        $user = new class($allowed) extends User {
            public function __construct(private array $allowed) { parent::__construct(); }
            public function can($ability, $arguments = []): bool { return in_array($ability, $this->allowed, true); }
        };
        $user->id = 900; $user->business_id = 7;
        return $user;
    }
}
