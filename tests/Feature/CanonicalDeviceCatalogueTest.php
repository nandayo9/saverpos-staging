<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;
use Modules\Recommerce\Services\CanonicalDeviceCatalogue;
use Modules\Recommerce\Services\Intelligence\IntelligenceService;
use Modules\Recommerce\Services\Intelligence\RecordStore;
use Tests\TestCase;

class CanonicalDeviceCatalogueTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        Schema::create('products', function (Blueprint $t): void { $t->integer('id')->primary(); $t->integer('business_id'); });
        Schema::create('variations', function (Blueprint $t): void { $t->integer('id')->primary(); $t->integer('product_id'); });
        DB::table('products')->insert(['id' => 1, 'business_id' => 7]);
        DB::table('variations')->insert([['id' => 11, 'product_id' => 1], ['id' => 12, 'product_id' => 1]]);
        (require base_path('Modules/Recommerce/Database/Migrations/2026_09_08_000001_create_trade_in_intelligence_records.php'))->up();
        (require base_path('Modules/Recommerce/Database/Migrations/2026_09_08_000003_create_canonical_device_catalogue.php'))->up();
    }

    private function variant(array $override = []): array
    {
        return array_replace(['variant_id' => 'TEST-S22-128', 'model_id' => 'TEST-S22', 'public_slug' => 'samsung-galaxy-s22',
            'category' => 'PHONE', 'brand' => 'Samsung', 'model_label' => 'Galaxy S22', 'family' => 'Galaxy S',
            'specification' => ['storage' => '128GB'], 'native_variation_id' => 11,
            'identity_provenance' => 'ISOLATED-TEST-ONLY', 'status' => 'VERIFIED'], $override);
    }

    private function import(array $v): void
    {
        app(IntelligenceService::class)->import(7, ['kind' => 'VARIANT', 'target' => $v['variant_id'], 'data' => $v], 900, 'Test identity import.');
    }

    private function publication(): array
    {
        return ['expected_revision' => 1, 'publication_state' => 'PUBLISHED', 'synthetic' => true, 'review_reference' => 'ISOLATED-TEST',
            'summary' => 'Synthetic Galaxy S22 model for testing permanent model pages with a separately identified 128GB configuration.',
            'condition_guidance' => 'Check the exact unit for published screen and body condition. This fixture is not proof of a real inspection or warranty.',
            'configuration_guidance' => 'Confirm the storage capacity on your device before continuing. An available model does not imply every configuration is in stock.'];
    }

    public function test_model_is_permanent_and_has_no_inventory_or_price_authority(): void
    {
        $this->import($this->variant());
        $catalogue = app(CanonicalDeviceCatalogue::class);
        self::assertSame([], $catalogue->models(7));
        $catalogue->publish(7, 'TEST-S22', $this->publication(), 900, 'Test editorial review.');
        $model = $catalogue->models(7)[0];
        self::assertSame('TEST-S22', $model['canonical_model_id']);
        self::assertFalse($model['indexable']);
        self::assertTrue($model['synthetic']);
        self::assertSame('TEST-S22-128', $catalogue->specifications(7, $model['slug'])[0]['id']);
        self::assertSame([], $catalogue->models(8));
        self::assertFalse(Schema::hasTable('recommerce_devices'));
        self::assertArrayNotHasKey('price', $model);
    }

    public function test_duplicate_name_spacing_and_case_do_not_create_new_model(): void
    {
        $this->import($this->variant());
        try {
            $this->import($this->variant(['variant_id' => 'OTHER', 'model_id' => 'DUPLICATE', 'brand' => ' samsung ', 'model_label' => ' Galaxy   S22 ', 'native_variation_id' => 12]));
            self::fail('Duplicate was accepted.');
        } catch (LogicException $e) { self::assertStringContainsString('Duplicate model', $e->getMessage()); }
        self::assertSame(1, DB::table(CanonicalDeviceCatalogue::MODELS)->count());
        self::assertSame(1, DB::table(RecordStore::TABLE)->count());
    }

    public function test_exact_variant_reimport_is_idempotent_but_reassignment_is_rejected(): void
    {
        $this->import($this->variant()); $this->import($this->variant());
        self::assertSame(1, DB::table(CanonicalDeviceCatalogue::MAPPINGS)->count());
        $this->expectException(LogicException::class);
        $this->import($this->variant(['specification' => ['storage' => '256GB']]));
    }

    public function test_duplicate_specification_does_not_get_a_second_variant_id(): void
    {
        $this->import($this->variant());
        $this->expectException(LogicException::class);
        $this->import($this->variant(['variant_id' => 'DUPLICATE', 'native_variation_id' => 12, 'specification' => ['storage' => '128gb']]));
    }

    public function test_thin_content_and_stale_revision_cannot_publish(): void
    {
        $this->import($this->variant());
        foreach ([['summary' => 'Phone'], ['expected_revision' => 0]] as $change) {
            try { app(CanonicalDeviceCatalogue::class)->publish(7, 'TEST-S22', array_replace($this->publication(), $change), 900, 'Test invalid publication.'); self::fail('Invalid publication accepted.'); }
            catch (LogicException $e) { self::assertNotEmpty($e->getMessage()); }
        }
        self::assertSame([], app(CanonicalDeviceCatalogue::class)->models(7));
    }

    public function test_phone_cannot_be_normalized_into_laptop_fields_or_other_tenant(): void
    {
        foreach ([['specification' => ['storage' => '128GB', 'processor' => 'Core i5']], ['native_variation_id' => 999]] as $change) {
            try { $this->import($this->variant($change)); self::fail('Invalid identity accepted.'); }
            catch (LogicException $e) { self::assertNotEmpty($e->getMessage()); }
        }
        self::assertSame(0, DB::table(CanonicalDeviceCatalogue::MODELS)->count());
    }
}
