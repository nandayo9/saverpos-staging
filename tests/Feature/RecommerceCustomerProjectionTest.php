<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Recommerce\Entities\Device;
use Modules\Recommerce\Http\Controllers\CustomerProjectionController;
use Modules\Recommerce\Http\Middleware\CustomerProjectionToken;
use Modules\Recommerce\Providers\RouteServiceProvider;
use Modules\Recommerce\Services\CustomerDeviceListingProjection;
use Modules\Recommerce\Services\CustomerProjectionAccess;
use Tests\TestCase;

class RecommerceCustomerProjectionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->detectEnvironment(fn (): string => 'staging');
        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'recommerce.enabled' => true,
            'recommerce.customer_projection' => [
                'enabled' => true,
                'bearer_token' => str_repeat('s', 48),
                'contract_version' => '1.0',
                'business_id' => 7,
                'location_ids' => [101],
                'variation_ids' => [303],
                'currency' => 'MYR',
            ],
        ]);
        DB::purge('sqlite');
        $schema = Schema::connection('sqlite');

        $schema->create('business_locations', function (Blueprint $table): void {
            $table->unsignedInteger('id')->primary();
            $table->unsignedInteger('business_id');
            $table->string('name');
        });
        $schema->create('brands', function (Blueprint $table): void {
            $table->unsignedInteger('id')->primary();
            $table->unsignedInteger('business_id');
            $table->string('name');
            $table->timestamp('deleted_at')->nullable();
        });
        $schema->create('products', function (Blueprint $table): void {
            $table->unsignedInteger('id')->primary();
            $table->unsignedInteger('business_id');
            $table->unsignedInteger('brand_id')->nullable();
            $table->string('name');
        });
        $schema->create('variations', function (Blueprint $table): void {
            $table->unsignedInteger('id')->primary();
            $table->unsignedInteger('product_id');
            $table->string('name');
            $table->string('sub_sku')->nullable();
            $table->timestamp('deleted_at')->nullable();
        });
        $schema->create('recommerce_devices', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('business_id');
            $table->uuid('device_uuid');
            $table->uuid('public_device_id')->nullable();
            $table->string('device_code');
            $table->string('category_code')->nullable();
            $table->string('ownership_kind');
            $table->string('custody_kind');
            $table->unsignedInteger('current_location_id')->nullable();
            $table->unsignedInteger('product_id')->nullable();
            $table->unsignedInteger('variation_id')->nullable();
            $table->string('lifecycle_state');
            $table->string('stock_participation');
            $table->string('transfer_state')->default('NONE');
            $table->string('listing_publication_state')->default('DRAFT');
            $table->decimal('listing_price', 22, 4)->nullable();
            $table->char('listing_currency', 3)->default('MYR');
            $table->string('listing_model_slug')->nullable();
            $table->string('listing_specification_id')->nullable();
            $table->json('specifications_json')->nullable();
            $table->string('manufacturer_serial_display')->nullable();
            $table->dateTime('sold_at')->nullable();
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamps();
        });
        $schema->create('recommerce_device_inspections', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('device_id');
            $table->string('status');
        });

        DB::table('business_locations')->insert(['id' => 101, 'business_id' => 7, 'name' => 'Kota Kinabalu']);
        DB::table('business_locations')->insert(['id' => 102, 'business_id' => 7, 'name' => 'Sandakan']);
        DB::table('brands')->insert(['id' => 10, 'business_id' => 7, 'name' => 'Lenovo']);
        DB::table('products')->insert(['id' => 202, 'business_id' => 7, 'brand_id' => 10, 'name' => 'ThinkPad T14 Gen 2']);
        DB::table('variations')->insert(['id' => 303, 'product_id' => 202, 'name' => 'i5 / 16GB / 512GB']);
        DB::table('variations')->insert(['id' => 304, 'product_id' => 202, 'name' => 'Out of cohort']);
    }

    public function test_projection_exposes_only_a_published_exact_device_with_an_exact_price(): void
    {
        $device = $this->device();
        DB::table('recommerce_device_inspections')->insert(['device_id' => $device->id, 'status' => 'PASSED']);

        $record = app(CustomerDeviceListingProjection::class)->device((string) $device->public_device_id);

        $this->assertNotNull($record);
        $this->assertSame('AVAILABLE', $record['availability']['state']);
        $this->assertFalse($record['availability']['transaction_enabled']);
        $this->assertSame(124900, $record['price']['amount_minor']);
        $this->assertSame('RECORDED', $record['passport']['inspection']['status']);
        $this->assertSame('NOT_RECORDED', $record['passport']['battery']['status']);
        $json = json_encode($record, JSON_THROW_ON_ERROR);
        foreach (['manufacturer_serial_display', 'acquisition_cost', 'supplier', 'device_code', 'product_id', 'variation_id', 'internal-note'] as $private) {
            $this->assertStringNotContainsString($private, $json);
        }
    }

    public function test_operational_or_merchandising_failures_are_excluded_not_downgraded_to_available(): void
    {
        foreach ([
            ['lifecycle_state' => 'RECEIVED_PENDING_INSPECTION'],
            ['lifecycle_state' => 'DIAGNOSIS'],
            ['lifecycle_state' => 'REFURBISHMENT_REQUIRED'],
            ['lifecycle_state' => 'QC'],
            ['lifecycle_state' => 'IN_TRANSIT'],
            ['lifecycle_state' => 'SOLD', 'sold_at' => now()],
            ['lifecycle_state' => 'RETURNED_PENDING_INSPECTION'],
            ['lifecycle_state' => 'QUARANTINED'],
            ['stock_participation' => 'RESERVED'],
            ['custody_kind' => 'IN_TRANSIT'],
            ['transfer_state' => 'IN_TRANSIT'],
            ['current_location_id' => 102],
            ['variation_id' => 304],
            ['listing_publication_state' => 'DRAFT'],
            ['listing_price' => null],
            ['listing_model_slug' => null],
            ['listing_specification_id' => null],
        ] as $override) {
            $device = $this->device($override);
            $this->assertNull(
                app(CustomerDeviceListingProjection::class)->device((string) $device->public_device_id),
                'ineligible Device appeared publicly for ' . key($override)
            );
        }
    }

    public function test_malformed_product_variation_relationship_is_excluded(): void
    {
        DB::table('products')->insert(['id' => 203, 'business_id' => 7, 'brand_id' => 10, 'name' => 'Wrong product']);
        $device = $this->device(['product_id' => 203]);

        $this->assertNull(app(CustomerDeviceListingProjection::class)->device((string) $device->public_device_id));
    }

    public function test_token_guard_is_staging_only_and_never_accepts_a_missing_or_wrong_secret(): void
    {
        $middleware = app(CustomerProjectionToken::class);
        $next = fn (): string => 'allowed';

        $missing = $middleware->handle(Request::create('/api/customer-projection/v1/models'), $next);
        $this->assertSame(401, $missing->getStatusCode());
        $this->assertStringContainsString('no-store', (string) $missing->headers->get('Cache-Control'));
        $wrong = $middleware->handle(Request::create('/api/customer-projection/v1/models', 'GET', [], [], [], ['HTTP_AUTHORIZATION' => 'Bearer wrong']), $next);
        $this->assertSame(401, $wrong->getStatusCode());
        $allowed = $middleware->handle(Request::create('/api/customer-projection/v1/models', 'GET', [], [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . str_repeat('s', 48)]), $next);
        $this->assertSame('allowed', $allowed);

        $this->app->detectEnvironment(fn (): string => 'production');
        $production = $middleware->handle(Request::create('/api/customer-projection/v1/models', 'GET', [], [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . str_repeat('s', 48)]), $next);
        $this->assertSame(404, $production->getStatusCode());

        $this->app->detectEnvironment(fn (): string => 'staging');
        config(['recommerce.customer_projection.contract_version' => '9.9']);
        $unsupportedContract = $middleware->handle(Request::create('/api/customer-projection/v1/models', 'GET', [], [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . str_repeat('s', 48)]), $next);
        $this->assertSame(404, $unsupportedContract->getStatusCode());
    }

    public function test_controller_returns_a_versioned_allowlisted_envelope_and_neutral_not_found(): void
    {
        $device = $this->device();
        $controller = app(CustomerProjectionController::class);

        $response = $controller->device((string) $device->public_device_id);
        $this->assertSame(200, $response->getStatusCode());
        $payload = $response->getData(true);
        $this->assertSame('1.0', $payload['meta']['contract_version']);
        $this->assertSame('SAVERPOS', $payload['meta']['authoritative_source']);
        $this->assertArrayNotHasKey('device_code', $payload['data']);
        $this->assertSame('noindex, nofollow, noarchive', $response->headers->get('X-Robots-Tag'));

        $missing = $controller->device('00000000-0000-0000-0000-000000000000');
        $this->assertSame(404, $missing->getStatusCode());
        $this->assertSame(['message' => 'Resource not found.'], $missing->getData(true));
    }

    public function test_read_only_api_route_requires_the_staging_connector_token(): void
    {
        $device = $this->device();
        (new RouteServiceProvider(app()))->map();

        $this->getJson('/api/customer-projection/v1/devices/' . $device->public_device_id)
            ->assertStatus(401)
            ->assertExactJson(['message' => 'Customer projection unavailable.']);
        $this->withHeader('Authorization', 'Bearer ' . str_repeat('s', 48))
            ->getJson('/api/customer-projection/v1/devices/' . $device->public_device_id)
            ->assertOk()
            ->assertJsonPath('data.public_device_id', $device->public_device_id)
            ->assertJsonMissingPath('data.device_code')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive');
    }

    /** @param array<string, mixed> $overrides */
    private function device(array $overrides = []): Device
    {
        $data = array_merge([
            'business_id' => 7,
            'device_uuid' => (string) \Illuminate\Support\Str::uuid(),
            'public_device_id' => (string) \Illuminate\Support\Str::uuid(),
            'device_code' => 'SB-DV-INTERNAL-001',
            'category_code' => 'LAPTOP',
            'ownership_kind' => 'BUSINESS',
            'custody_kind' => 'LOCATION',
            'current_location_id' => 101,
            'product_id' => 202,
            'variation_id' => 303,
            'lifecycle_state' => 'AVAILABLE',
            'stock_participation' => 'ON_HAND',
            'transfer_state' => 'NONE',
            'listing_publication_state' => 'PUBLISHED',
            'listing_price' => 1249.00,
            'listing_currency' => 'MYR',
            'listing_model_slug' => 'lenovo-thinkpad-t14-gen-2',
            'listing_specification_id' => 'SPEC-T14G2-I5-16-512',
            'specifications_json' => json_encode([
                'brand' => 'Lenovo',
                'model' => 'ThinkPad T14 Gen 2',
                'generation' => 'Gen 2',
                'cpu' => 'Intel Core i5',
                'ram' => '16GB',
                'storage' => '512GB SSD',
            ], JSON_THROW_ON_ERROR),
            'manufacturer_serial_display' => 'PRIVATE-SERIAL-DO-NOT-LEAK',
            'lock_version' => 7,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides);

        return Device::query()->create($data);
    }
}
