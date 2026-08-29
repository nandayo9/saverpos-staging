<?php

namespace Tests\Feature;

use App\Http\Controllers\WalkInController;
use App\Services\WalkInService;
use App\Transaction;
use App\User;
use App\WalkIn;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class WalkInServiceTest extends TestCase
{
    private User $user;
    private string $originalTimezone;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalTimezone = date_default_timezone_get();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'database.connections.sqlite.foreign_key_constraints' => true]);
        DB::purge('sqlite');
        $schema = Schema::connection('sqlite');

        $schema->create('business', fn (Blueprint $table) => $table->unsignedInteger('id')->primary());
        $schema->create('business_locations', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->unsignedInteger('business_id');
            $table->string('name')->nullable();
            $table->string('location_id')->nullable();
            $table->string('receipt_printer_type')->nullable();
            $table->unsignedInteger('selling_price_group_id')->nullable();
            $table->text('default_payment_accounts')->nullable();
            $table->unsignedInteger('invoice_scheme_id')->nullable();
            $table->unsignedInteger('invoice_layout_id')->nullable();
            $table->unsignedInteger('sale_invoice_scheme_id')->nullable();
            $table->boolean('is_active')->default(true);
        });
        $schema->create('selling_price_groups', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('business_id');
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();
        });
        $schema->create('users', function (Blueprint $table) { $table->unsignedInteger('id')->primary(); $table->unsignedInteger('business_id'); $table->string('username')->nullable(); $table->timestamp('deleted_at')->nullable(); $table->timestamps(); });
        $schema->create('permissions', function (Blueprint $table) { $table->increments('id'); $table->string('name'); $table->string('guard_name'); $table->timestamps(); });
        $schema->create('roles', function (Blueprint $table) { $table->increments('id'); $table->string('name'); $table->unsignedInteger('business_id')->nullable(); $table->string('guard_name'); $table->timestamps(); });
        $schema->create('model_has_permissions', function (Blueprint $table) { $table->unsignedInteger('permission_id'); $table->string('model_type'); $table->unsignedInteger('model_id'); $table->primary(['permission_id', 'model_id', 'model_type']); });
        $schema->create('model_has_roles', function (Blueprint $table) { $table->unsignedInteger('role_id'); $table->string('model_type'); $table->unsignedInteger('model_id'); $table->primary(['role_id', 'model_id', 'model_type']); });
        $schema->create('role_has_permissions', function (Blueprint $table) { $table->unsignedInteger('permission_id'); $table->unsignedInteger('role_id'); $table->primary(['permission_id', 'role_id']); });
        $schema->create('transactions', function (Blueprint $table) { $table->increments('id'); $table->unsignedInteger('business_id'); $table->unsignedInteger('location_id'); $table->string('type'); $table->string('status'); $table->decimal('final_total', 22, 4)->default(0); $table->timestamps(); });
        $schema->create('activity_log', function (Blueprint $table) { $table->increments('id'); $table->string('log_name')->nullable(); $table->text('description'); $table->string('subject_type')->nullable(); $table->unsignedInteger('subject_id')->nullable(); $table->string('causer_type')->nullable(); $table->unsignedInteger('causer_id')->nullable(); $table->text('properties')->nullable(); $table->string('event')->nullable(); $table->string('batch_uuid')->nullable(); $table->unsignedInteger('business_id')->nullable(); $table->timestamps(); });

        $migration = require base_path('database/migrations/2026_08_29_000001_create_walk_ins_table.php');
        $migration->up();
        $pdo = DB::connection('sqlite')->getPdo();
        $pdo->sqliteCreateFunction('IF', fn ($condition, $whenTrue, $whenFalse) => $condition ? $whenTrue : $whenFalse, 3);
        $pdo->sqliteCreateFunction('CONCAT', fn (...$parts) => implode('', $parts), -1);
        DB::table('business')->insert(['id' => 7]);
        DB::table('business_locations')->insert([['id' => 101, 'business_id' => 7, 'name' => 'KK'], ['id' => 102, 'business_id' => 7, 'name' => 'Tawau']]);
        DB::table('users')->insert(['id' => 900, 'business_id' => 7, 'username' => 'manager']);
        foreach (array_merge(config('walkin.permissions'), ['access_all_locations']) as $name) { Permission::create(['name' => $name, 'guard_name' => 'web']); }
        $this->user = User::findOrFail(900);
        $this->user->givePermissionTo(array_merge(config('walkin.permissions'), ['access_all_locations']));
        Auth::setUser($this->user);
        session(['business' => (object) ['id' => 7, 'time_zone' => 'Asia/Kuching']]);
        date_default_timezone_set('Asia/Kuching');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        date_default_timezone_set($this->originalTimezone);
        DB::disconnect('sqlite');
        parent::tearDown();
    }

    public function test_capture_records_authenticated_user_branch_and_arrival_time(): void
    {
        Carbon::setTestNow('2026-08-29 09:30:00');
        $walkIn = $this->service()->capture($this->user, 101);

        $this->assertSame(7, $walkIn->business_id);
        $this->assertSame(101, $walkIn->location_id);
        $this->assertSame(900, $walkIn->recorded_by);
        $this->assertSame(WalkIn::STATUS_OPEN, $walkIn->status);
        $this->assertSame('2026-08-29 09:30:00', $walkIn->arrived_at->format('Y-m-d H:i:s'));
    }

    public function test_capture_rejects_a_user_without_access_to_the_branch(): void
    {
        DB::table('users')->insert(['id' => 901, 'business_id' => 7, 'username' => 'restricted']);
        $restricted = User::findOrFail(901);
        $restricted->givePermissionTo('walkin.create');
        Auth::setUser($restricted);

        $this->expectException(AuthorizationException::class);
        $this->service()->capture($restricted, 101);
    }

    public function test_only_final_same_branch_pos_sale_can_convert_once_and_revenue_is_authoritative(): void
    {
        $walkIn = $this->service()->capture($this->user, 101);
        $sale = $this->sale(101, 'final', 15420);
        $converted = $this->service()->convert($this->user, $walkIn->id, $sale);

        $this->assertSame(WalkIn::STATUS_CONVERTED, $converted->status);
        $this->assertSame($sale->id, $converted->transaction_id);
        $summary = $this->summaryFor($walkIn);
        $this->assertSame(1, $summary['walk_ins']);
        $this->assertSame(1, $summary['converted']);
        $this->assertSame(100.0, $summary['conversion_rate']);
        $this->assertSame(15420.0, $summary['revenue']);

        $this->expectException(LogicException::class);
        $this->service()->convert($this->user, $walkIn->id, $sale);
    }

    public function test_cross_branch_or_non_final_sales_are_rejected(): void
    {
        $walkIn = $this->service()->capture($this->user, 101);
        try {
            $this->service()->convert($this->user, $walkIn->id, $this->sale(102, 'final', 1));
            $this->fail('Cross-branch attribution was accepted.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('same branch', $exception->getMessage());
        }
        try {
            $this->service()->convert($this->user, $walkIn->id, $this->sale(101, 'draft', 1));
            $this->fail('Draft sale attribution was accepted.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('completed POS sale', $exception->getMessage());
        }
    }

    public function test_no_sale_requires_a_configured_reason_and_cannot_overwrite_conversion(): void
    {
        $walkIn = $this->service()->capture($this->user, 101);
        $closed = $this->service()->closeAsNoSale($this->user, $walkIn->id, 'NO_SUITABLE_STOCK');
        $this->assertSame(WalkIn::STATUS_NO_SALE, $closed->status);
        $this->assertSame('NO_SUITABLE_STOCK', $closed->no_sale_reason);
        try {
            $this->service()->convert($this->user, $walkIn->id, $this->sale(101, 'final', 50));
            $this->fail('A no-sale walk-in was converted.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('open walk-in', $exception->getMessage());
        }
        $this->expectException(LogicException::class);
        $this->service()->closeAsNoSale($this->user, $walkIn->id, 'NOT_A_REASON');
    }

    public function test_released_or_invalidated_sale_returns_visit_to_open_and_removes_revenue(): void
    {
        $walkIn = $this->service()->capture($this->user, 101);
        $sale = $this->sale(101, 'final', 99);
        $this->service()->convert($this->user, $walkIn->id, $sale);
        $this->service()->releaseConversionForTransaction($sale, $this->user);
        $walkIn->refresh();

        $this->assertSame(WalkIn::STATUS_OPEN, $walkIn->status);
        $this->assertNull($walkIn->transaction_id);
        $summary = $this->summaryFor($walkIn);
        $this->assertSame(0, $summary['converted']);
        $this->assertSame(1, $summary['open']);
        $this->assertSame(0.0, $summary['revenue']);
    }

    public function test_dashboard_controller_reports_conversion_and_no_sale_data(): void
    {
        $converted = $this->service()->capture($this->user, 101);
        $this->service()->convert($this->user, $converted->id, $this->sale(101, 'final', 125.50));
        $noSale = $this->service()->capture($this->user, 101);
        $this->service()->closeAsNoSale($this->user, $noSale->id, 'NO_SUITABLE_STOCK');

        $request = Request::create('/walk-ins', 'GET', ['location_id' => 101]);
        $request->setUserResolver(fn () => $this->user);
        $view = app(WalkInController::class)->index($request, $this->service());
        $data = $view->getData();

        $this->assertSame('walk_ins.index', $view->getName());
        $this->assertSame(2, $data['summary']['walk_ins']);
        $this->assertSame(1, $data['summary']['converted']);
        $this->assertSame(1, $data['summary']['no_sale']);
        $this->assertSame(50.0, $data['summary']['conversion_rate']);
        $this->assertSame(125.5, $data['summary']['revenue']);
        $this->assertSame('No Suitable Stock', $data['reasons']->first()['label']);
        $this->assertCount(2, $data['walkIns']);
    }

    public function test_branch_limited_viewer_cannot_request_another_branch_dashboard(): void
    {
        DB::table('users')->insert(['id' => 901, 'business_id' => 7, 'username' => 'branch-viewer']);
        Permission::create(['name' => 'location.101', 'guard_name' => 'web']);
        $branchViewer = User::findOrFail(901);
        $branchViewer->givePermissionTo(['walkin.view', 'location.101']);
        Auth::setUser($branchViewer);

        $otherBranchRequest = Request::create('/walk-ins', 'GET', ['location_id' => 102]);
        $otherBranchRequest->setUserResolver(fn () => $branchViewer);

        try {
            app(WalkInController::class)->index($otherBranchRequest, $this->service());
            $this->fail('A branch-limited viewer could request another branch dashboard.');
        } catch (AuthorizationException) {
            $this->assertTrue(true);
        }

        $currentBranchRequest = Request::create('/walk-ins', 'GET');
        $currentBranchRequest->setUserResolver(fn () => $branchViewer);
        $view = app(WalkInController::class)->index($currentBranchRequest, $this->service());

        $this->assertSame(101, (int) $view->getData()['locationId']);
        $this->assertSame([101], array_map('intval', array_keys($view->getData()['locations'])));
    }

    public function test_dashboard_exposes_consistent_date_presets(): void
    {
        Carbon::setTestNow('2026-08-29 10:00:00');
        $request = Request::create('/walk-ins', 'GET', ['location_id' => 101]);
        $request->setUserResolver(fn () => $this->user);

        $view = app(WalkInController::class)->index($request, $this->service());

        $this->assertSame([
            ['label' => 'Today', 'start' => '2026-08-29', 'end' => '2026-08-29'],
            ['label' => 'Yesterday', 'start' => '2026-08-28', 'end' => '2026-08-28'],
            ['label' => 'Last 7 Days', 'start' => '2026-08-23', 'end' => '2026-08-29'],
            ['label' => 'This Month', 'start' => '2026-08-01', 'end' => '2026-08-29'],
        ], $view->getData()['datePresets']);
    }

    public function test_capture_and_open_endpoints_support_the_current_branch(): void
    {
        $controller = app(WalkInController::class);
        $storeRequest = Request::create('/walk-ins', 'POST', ['location_id' => 101]);
        $storeRequest->setUserResolver(fn () => $this->user);
        $stored = $controller->store($storeRequest, $this->service())->getData(true);

        $this->assertTrue($stored['success']);
        $this->assertSame('Walk-In #1', $stored['walk_in']['label']);

        $openRequest = Request::create('/walk-ins/open', 'GET', ['location_id' => 101]);
        $openRequest->setUserResolver(fn () => $this->user);
        $open = $controller->open($openRequest)->values()->all();

        $this->assertSame(1, $open[0]['id']);
        $this->assertSame('Walk-In #1 · '.now()->format('H:i'), $open[0]['label']);
    }

    public function test_close_endpoint_records_a_stable_reason_code(): void
    {
        $walkIn = $this->service()->capture($this->user, 101);
        $request = Request::create('/walk-ins/'.$walkIn->id.'/close', 'POST', ['no_sale_reason' => 'STILL_CONSIDERING']);
        $request->setUserResolver(fn () => $this->user);

        $response = app(WalkInController::class)->close($request, $walkIn, $this->service());
        $this->assertSame(route('walk-ins.index'), $response->getTargetUrl());

        $this->assertDatabaseHas('walk_ins', [
            'id' => $walkIn->id,
            'status' => WalkIn::STATUS_NO_SALE,
            'no_sale_reason' => 'STILL_CONSIDERING',
        ]);
    }

    private function sale(int $locationId, string $status, float $total): Transaction
    {
        return Transaction::create(['business_id' => 7, 'location_id' => $locationId, 'type' => 'sell', 'status' => $status, 'final_total' => $total]);
    }

    private function service(): WalkInService
    {
        return app(WalkInService::class);
    }

    private function summaryFor(WalkIn $walkIn): array
    {
        return $this->service()->summary(7, 101, $walkIn->arrived_at->copy()->startOfDay(), $walkIn->arrived_at->copy()->endOfDay());
    }
}
