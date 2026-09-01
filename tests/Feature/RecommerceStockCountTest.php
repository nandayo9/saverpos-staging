<?php

namespace Tests\Feature;

use App\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Mockery;
use Modules\Recommerce\Entities\Device;
use Modules\Recommerce\Entities\StockCountSession;
use Modules\Recommerce\Services\StockCountService;
use Modules\Recommerce\Services\UltimatePosStockCountAdjustmentWriter;
use Modules\Recommerce\Support\AuthorizationGate;
use Modules\Recommerce\Support\CohortPolicy;
use Tests\TestCase;

class RecommerceStockCountTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:',
            'database.connections.sqlite.foreign_key_constraints' => true,
            'recommerce.enabled' => true, 'recommerce.writes_enabled' => true,
            'recommerce.permissions' => ['recommerce.stockcount.view', 'recommerce.stockcount.create', 'recommerce.stockcount.count', 'recommerce.stockcount.review', 'recommerce.stockcount.approve', 'recommerce.stockcount.reconcile', 'recommerce.stockcount.close'],
            'recommerce.cohort.business_id' => 7, 'recommerce.cohort.location_id' => 101,
            'recommerce.cohort.location_ids' => [101, 102], 'recommerce.cohort.variation_ids' => [303, 304],
            'recommerce.stock_count.approval.serialized_requires_approval' => true,
            'recommerce.stock_count.approval.generic_cost_threshold' => null,
        ]);
        DB::purge('sqlite'); $schema = Schema::connection('sqlite');
        $schema->create('business', fn (Blueprint $t) => $t->unsignedInteger('id')->primary());
        $schema->create('business_locations', function (Blueprint $t) { $t->unsignedInteger('id')->primary(); $t->unsignedInteger('business_id'); $t->string('name'); });
        $schema->create('users', function (Blueprint $t) { $t->unsignedInteger('id')->primary(); $t->unsignedInteger('business_id'); });
        $schema->create('products', function (Blueprint $t) { $t->unsignedInteger('id')->primary(); $t->unsignedInteger('business_id'); $t->string('name'); });
        $schema->create('variations', function (Blueprint $t) { $t->unsignedInteger('id')->primary(); $t->unsignedInteger('product_id'); $t->string('name')->nullable(); $t->timestamp('deleted_at')->nullable(); });
        $schema->create('variation_location_details', function (Blueprint $t) { $t->increments('id'); $t->unsignedInteger('product_id'); $t->unsignedInteger('variation_id'); $t->unsignedInteger('location_id'); $t->decimal('qty_available', 22, 4); });
        $schema->create('recommerce_devices', function (Blueprint $t) { $t->bigIncrements('id'); $t->unsignedInteger('business_id'); $t->uuid('device_uuid'); $t->string('device_code'); $t->string('ownership_kind'); $t->string('custody_kind'); $t->unsignedInteger('current_location_id')->nullable(); $t->unsignedInteger('product_id')->nullable(); $t->unsignedInteger('variation_id')->nullable(); $t->string('lifecycle_state'); $t->string('stock_participation'); $t->unsignedInteger('lock_version')->default(1); $t->timestamps(); $t->unique(['business_id', 'device_code']); });
        $schema->create('recommerce_device_identifiers', function (Blueprint $t) { $t->bigIncrements('id'); $t->unsignedBigInteger('device_id'); $t->unsignedInteger('business_id'); $t->string('identifier_type'); $t->string('normalized_hash', 64); });
        $schema->create('recommerce_device_purchase_assignments', function (Blueprint $t) { $t->bigIncrements('id'); $t->unsignedBigInteger('device_id'); $t->decimal('unit_acquisition_cost', 22, 4)->nullable(); });
        $schema->create('recommerce_device_movements', function (Blueprint $t) { $t->bigIncrements('id'); $t->unsignedBigInteger('device_id'); $t->unsignedInteger('business_id'); $t->string('movement_type'); $t->dateTime('occurred_at'); });
        $schema->create('recommerce_serialization_profiles', function (Blueprint $t) { $t->bigIncrements('id'); $t->unsignedInteger('business_id'); $t->unsignedInteger('product_id'); $t->unsignedInteger('variation_id'); $t->string('mode'); });
        DB::table('business')->insert(['id' => 7]);
        DB::table('business_locations')->insert([['id' => 101, 'business_id' => 7, 'name' => 'Kota Kinabalu'], ['id' => 102, 'business_id' => 7, 'name' => 'Sandakan']]);
        DB::table('users')->insert(['id' => 900, 'business_id' => 7]);
        DB::table('products')->insert(['id' => 202, 'business_id' => 7, 'name' => 'Laptop']);
        DB::table('variations')->insert([['id' => 303, 'product_id' => 202, 'name' => 'Tracked'], ['id' => 304, 'product_id' => 202, 'name' => 'Accessory']]);
        DB::table('recommerce_serialization_profiles')->insert(['business_id' => 7, 'product_id' => 202, 'variation_id' => 303, 'mode' => 'TRACKED_REQUIRED']);
        $migration = require base_path('Modules/Recommerce/Database/Migrations/2026_09_01_000010_create_stock_count_tables.php'); $migration->up();
        Auth::setUser($this->user());
    }

    public function test_serialized_scans_are_exact_duplicate_safe_and_never_repair_location_or_state(): void
    {
        DB::table('variation_location_details')->insert(['product_id' => 202, 'variation_id' => 303, 'location_id' => 101, 'qty_available' => 2]);
        $expected = $this->device('SB-DV-EXPECTED', 101, 'AVAILABLE', 'ON_HAND');
        $missing = $this->device('SB-DV-MISSING', 101, 'AVAILABLE', 'ON_HAND');
        $wrongLocation = $this->device('SB-DV-OTHER-BRANCH', 102, 'AVAILABLE', 'ON_HAND');
        $wrongState = $this->device('SB-DV-SOLD', 101, 'SOLD', 'NONE');
        $service = $this->service();
        $session = $service->create($this->user(), 101, 'FULL_BRANCH', [303]);
        $session = $service->start($this->user(), $session->id);
        $this->assertSame('EXPECTED', $service->scan($this->user(), $session->id, $expected->device_code)['result']);
        $this->assertSame('DUPLICATE', $service->scan($this->user(), $session->id, $expected->device_code)['result']);
        $this->assertSame('WRONG_LOCATION', $service->scan($this->user(), $session->id, $wrongLocation->device_code)['result']);
        $this->assertSame('WRONG_STATE', $service->scan($this->user(), $session->id, $wrongState->device_code)['result']);
        $this->assertSame('UNKNOWN', $service->scan($this->user(), $session->id, 'not-a-device')['result']);
        $this->assertSame(1, DB::table('recommerce_stock_count_entries')->where('session_id', $session->id)->where('device_id', $expected->id)->count());
        $this->assertSame(102, $wrongLocation->fresh()->current_location_id);
        $this->assertSame('SOLD', $wrongState->fresh()->lifecycle_state);
        $service->review($this->user(), $session->id);
        $this->assertSame(1, $service->remaining(StockCountSession::findOrFail($session->id))->count());
        $this->assertSame($missing->id, (int) $service->remaining(StockCountSession::findOrFail($session->id))->first()->device_id);
    }

    public function test_snapshot_is_immutable_and_negative_generic_variance_uses_the_native_adapter_once(): void
    {
        DB::table('variation_location_details')->insert(['product_id' => 202, 'variation_id' => 304, 'location_id' => 101, 'qty_available' => 5]);
        $writer = Mockery::mock(UltimatePosStockCountAdjustmentWriter::class);
        $service = $this->service($writer);
        $session = $service->start($this->user(), $service->create($this->user(), 101, 'CYCLE_COUNT', [304])->id);
        $item = $session->items()->where('item_kind', 'NON_SERIALIZED_VARIATION')->firstOrFail();
        $this->assertSame(5.0, (float) $item->expected_quantity);
        DB::table('variation_location_details')->where('variation_id', 304)->update(['qty_available' => 99]);
        $service->recordQuantity($this->user(), $session->id, $item->id, 3);
        $session = $service->review($this->user(), $session->id);
        $exception = $session->exceptions()->where('exception_type', 'AGGREGATE_QUANTITY_VARIANCE')->firstOrFail();
        $service->resolve($this->user(), $session->id, $exception->id, 'MISCOUNT', 'Physical recount confirmed three accessories.');
        $writer->shouldReceive('writeNegativeVariance')->once()->andReturn(['transaction_id' => 44, 'line_id' => 55, 'quantity' => 2]);
        $reconciled = $service->reconcile($this->user(), $session->id);
        $this->assertSame('RECONCILED', $reconciled->status);
        $this->assertSame(5.0, (float) $reconciled->items()->firstOrFail()->expected_quantity, 'Later POS movement cannot rewrite the starting snapshot.');
        $this->assertSame('CLOSED', $service->close($this->user(), $session->id)->status);
    }

    public function test_post_snapshot_device_movement_blocks_reconciliation(): void
    {
        DB::table('variation_location_details')->insert(['product_id' => 202, 'variation_id' => 303, 'location_id' => 101, 'qty_available' => 1]);
        $device = $this->device('SB-DV-MOVED', 101, 'AVAILABLE', 'ON_HAND');
        $service = $this->service();
        $session = $service->start($this->user(), $service->create($this->user(), 101, 'FULL_BRANCH', [303])->id);
        $service->scan($this->user(), $session->id, $device->device_code);
        $service->review($this->user(), $session->id);
        DB::table('recommerce_device_movements')->insert(['device_id' => $device->id, 'business_id' => 7, 'movement_type' => 'SALE', 'occurred_at' => now()->addSecond(),]);
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Post-snapshot movements require a fresh count');
        $service->reconcile($this->user(), $session->id);
    }

    public function test_resolved_serialized_exception_requires_one_separate_approval(): void
    {
        DB::table('variation_location_details')->insert(['product_id' => 202, 'variation_id' => 303, 'location_id' => 101, 'qty_available' => 1]);
        $expected = $this->device('SB-DV-APPROVED-EXPECTED', 101, 'AVAILABLE', 'ON_HAND');
        $service = $this->service();
        $session = $service->start($this->user(), $service->create($this->user(), 101, 'FULL_BRANCH', [303])->id);
        $service->scan($this->user(), $session->id, $expected->device_code);
        $unexpected = $this->device('SB-DV-APPROVED-UNEXPECTED', 101, 'AVAILABLE', 'ON_HAND');
        $service->scan($this->user(), $session->id, $unexpected->device_code);
        $review = $service->review($this->user(), $session->id);
        $exception = $review->exceptions()->where('exception_type', 'UNEXPECTED_DEVICE')->firstOrFail();
        $service->resolve($this->user(), $session->id, $exception->id, 'OTHER', 'Device is held for separate identity investigation.');
        $awaiting = $service->submitForApproval($this->user(), $session->id);
        $this->assertSame('AWAITING_APPROVAL', $awaiting->status);
        $approved = $service->approve($this->user(), $session->id);
        $this->assertNotNull($approved->approved_at);
        $this->expectException(\LogicException::class);
        $service->approve($this->user(), $session->id);
    }

    public function test_user_without_branch_access_cannot_create_a_count_for_that_branch(): void
    {
        $user = $this->user([101]); Auth::setUser($user);
        $this->expectException(AuthorizationException::class);
        $this->service()->create($user, 102, 'FULL_BRANCH', [303]);
    }

    private function service($writer = null): StockCountService { return new StockCountService(new AuthorizationGate(new CohortPolicy()), $writer ?: Mockery::mock(UltimatePosStockCountAdjustmentWriter::class)); }
    private function device(string $code, int $locationId, string $state, string $participation): Device { return Device::create(['business_id' => 7, 'device_uuid' => (string) Str::uuid(), 'device_code' => $code, 'ownership_kind' => 'BUSINESS', 'custody_kind' => 'LOCATION', 'current_location_id' => $locationId, 'product_id' => 202, 'variation_id' => 303, 'lifecycle_state' => $state, 'stock_participation' => $participation, 'lock_version' => 1]); }
    private function user(array $locations = [101, 102]): User { $user = new class($locations) extends User { public function __construct(private array $locations) { parent::__construct(); } public function can($ability, $arguments = []): bool { return in_array($ability, config('recommerce.permissions', []), true); } public function permitted_locations($business_id = null) { return $this->locations; } }; $user->id = 900; $user->business_id = 7; return $user; }
}
