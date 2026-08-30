<?php

namespace Tests\Feature;

use App\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Recommerce\Services\LegacyRepairArchiveService;
use Modules\Recommerce\Support\AuthorizationGate;
use Modules\Recommerce\Support\CohortPolicy;
use Tests\TestCase;

class RecommerceLegacyRepairArchiveTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'recommerce.enabled' => true,
            'recommerce.writes_enabled' => true,
            'recommerce.permissions' => ['recommerce.repair.archive'],
            'recommerce.cohort.business_id' => 7,
            'recommerce.cohort.location_id' => 101,
            'recommerce.cohort.location_ids' => [101],
        ]);

        DB::purge('sqlite');
        $schema = Schema::connection('sqlite');
        $schema->create('system', function (Blueprint $table) {
            $table->increments('id');
            $table->string('key');
            $table->text('value')->nullable();
        });
        $schema->create('business', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
        });
        $schema->create('business_locations', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->unsignedInteger('business_id');
        });
        $schema->create('contacts', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->unsignedInteger('business_id');
            $table->string('type', 20)->nullable();
            $table->timestamp('deleted_at')->nullable();
        });
        $schema->create('users', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->unsignedInteger('business_id');
        });
        $schema->create('transactions', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('business_id');
            $table->unsignedInteger('location_id');
            $table->string('type');
            $table->string('status')->nullable();
            $table->string('sub_type')->nullable();
            $table->string('invoice_no')->nullable();
            $table->dateTime('transaction_date')->nullable();
            $table->decimal('final_total', 22, 4)->nullable();
            $table->unsignedInteger('contact_id')->nullable();
            $table->unsignedInteger('created_by')->nullable();
            $table->string('source')->nullable();
            $table->timestamps();
        });
        (require base_path('Modules/Recommerce/Database/Migrations/2026_08_30_000026_create_recommerce_repair_archives.php'))->up();

        DB::table('business')->insert(['id' => 7]);
        DB::table('users')->insert(['id' => 900, 'business_id' => 7]);
        DB::table('business_locations')->insert(['id' => 101, 'business_id' => 7]);
        DB::table('contacts')->insert(['id' => 405, 'business_id' => 7, 'type' => 'customer']);
        DB::table('transactions')->insert([
            ['id' => 9001, 'business_id' => 7, 'location_id' => 101, 'type' => 'sell', 'status' => 'final', 'sub_type' => 'repair', 'invoice_no' => 'LEGACY-001', 'transaction_date' => now()->subMonth(), 'final_total' => 120.0, 'contact_id' => 405],
            ['id' => 9002, 'business_id' => 7, 'location_id' => 101, 'type' => 'sell', 'status' => 'final', 'sub_type' => 'repair', 'invoice_no' => 'LEGACY-002', 'transaction_date' => now()->subWeek(), 'final_total' => 80.0, 'contact_id' => 405],
        ]);
    }

    protected function tearDown(): void
    {
        DB::disconnect('sqlite');
        parent::tearDown();
    }

    public function test_it_archives_legacy_repair_transactions_once(): void
    {
        $service = $this->service();
        $result = $service->archive($this->user(), '88888888-8888-4888-8888-888888888801');
        $this->assertSame(2, $result['scanned']);
        $this->assertSame(2, $result['archived']);
        $this->assertSame(0, $result['skipped']);
        $this->assertSame(2, DB::table('recommerce_repair_archives')->count());

        $repeat = $service->archive($this->user(), '77777777-7777-4777-8777-777777777701');
        $this->assertSame(2, $repeat['scanned']);
        $this->assertSame(0, $repeat['archived']);
        $this->assertSame(2, $repeat['skipped']);
        $this->assertSame(2, DB::table('recommerce_repair_archives')->count());
        $this->assertSame(2, DB::table('transactions')->where('sub_type', 'repair')->count());
        $this->assertSame('LEGACY-001', DB::table('transactions')->where('id', 9001)->value('invoice_no'));
        $archive = DB::table('recommerce_repair_archives')->where('transaction_id', 9001)->first();
        $this->assertStringContainsString('LEGACY-001', $archive->snapshot_json);
        $this->assertStringContainsString('POS_TRANSACTION_ONLY', $archive->snapshot_json);
    }

    public function test_a_missing_archive_permission_is_denied(): void
    {
        config(['recommerce.permissions' => []]);
        $this->expectException(AuthorizationException::class);
        $this->service()->archive($this->user(), '88888888-8888-4888-8888-888888888802');
    }

    public function test_catalogued_but_ungranted_archive_permission_is_denied(): void
    {
        $this->expectException(AuthorizationException::class);
        $this->service()->archive($this->user(false), '88888888-8888-4888-8888-888888888803');
    }

    public function test_transactions_outside_the_configured_location_are_not_archived(): void
    {
        DB::table('transactions')->insert([
            'id' => 9003,
            'business_id' => 7,
            'location_id' => 202,
            'type' => 'sell',
            'status' => 'final',
            'sub_type' => 'repair',
            'invoice_no' => 'OUTSIDE-001',
            'transaction_date' => now(),
            'final_total' => 50.0,
            'contact_id' => 405,
        ]);

        $result = $this->service()->archive($this->user(), '88888888-8888-4888-8888-888888888804');

        $this->assertSame(2, $result['scanned']);
        $this->assertSame(2, $result['archived']);
        $this->assertDatabaseMissing('recommerce_repair_archives', ['transaction_id' => 9003]);
    }

    public function test_archive_snapshot_survives_source_transaction_deletion(): void
    {
        $this->service()->archive($this->user(), '88888888-8888-4888-8777-888888888805');

        DB::table('transactions')->where('id', 9001)->delete();

        $archive = DB::table('recommerce_repair_archives')
            ->where('snapshot_json', 'like', '%LEGACY-001%')
            ->first();

        $this->assertNotNull($archive);
        $this->assertNull($archive->transaction_id);
        $this->assertStringContainsString('LEGACY-001', $archive->snapshot_json);
    }

    public function test_archive_route_is_scoped_and_idempotent(): void
    {
        (new \Modules\Recommerce\Providers\RouteServiceProvider(app()))->map();
        app('router')->getRoutes()->refreshNameLookups();
        app('url')->setRoutes(app('router')->getRoutes());

        $response = $this->actingAs($this->user())
            ->postJson('/recommerce/repair/legacy-archive', [
                'command_uuid' => 'aaa88888-8888-4888-8888-888888888801',
            ]);

        $response->assertCreated()
            ->assertJsonPath('status', 'LEGACY_REPAIR_ARCHIVE_RUN')
            ->assertJsonPath('archived', 2)
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('Referrer-Policy', 'no-referrer');

        $index = $this->actingAs($this->user())
            ->getJson('/recommerce/repair/legacy-archive');
        $index->assertOk()
            ->assertJsonPath('status', 'LEGACY_REPAIR_ARCHIVES')
            ->assertJsonCount(2, 'archives')
            ->assertHeader('Cache-Control', 'no-store, private');

        $archiveId = $index->json('archives.0.id');
        $detail = $this->actingAs($this->user())
            ->getJson('/recommerce/repair/legacy-archive/'.$archiveId);
        $detail->assertOk()
            ->assertJsonPath('status', 'LEGACY_REPAIR_ARCHIVE')
            ->assertJsonPath('archive.snapshot_json.source_scope', 'POS_TRANSACTION_ONLY');

        $repeat = $this->actingAs($this->user())
            ->postJson('/recommerce/repair/legacy-archive', [
                'command_uuid' => 'aaa88888-8888-4888-8888-888888888802',
            ]);

        $repeat->assertCreated();
        $this->assertSame(2, $repeat->json('skipped'));
        $this->assertSame(2, DB::table('recommerce_repair_archives')->count());
        $this->assertSame(2, DB::table('transactions')->where('sub_type', 'repair')->count());
    }

    protected function service(): LegacyRepairArchiveService
    {
        return new LegacyRepairArchiveService(new AuthorizationGate(new CohortPolicy()));
    }

    protected function user(bool $granted = true): User
    {
        $user = new class extends User {
            public bool $archivePermissionGranted = true;

            public function can($ability, $arguments = []): bool
            {
                return $ability === 'recommerce.repair.archive' && $this->archivePermissionGranted;
            }
        };
        $user->id = 900;
        $user->business_id = 7;
        $user->archivePermissionGranted = $granted;

        return $user;
    }
}
