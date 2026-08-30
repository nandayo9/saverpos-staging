<?php

namespace Tests\Feature;

use App\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Auth;
use Modules\Recommerce\Entities\RepairJob;
use Modules\Recommerce\Services\RepairCollectionService;
use Modules\Recommerce\Services\WarrantyClaimService;
use Modules\Recommerce\Support\AuthorizationGate;
use Modules\Recommerce\Support\CohortPolicy;
use Tests\TestCase;

class RecommerceWarrantyClaimTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'database.connections.sqlite.foreign_key_constraints' => true,
            'recommerce.enabled' => true,
            'recommerce.writes_enabled' => true,
            'recommerce.permissions' => [
                'recommerce.repair.view',
                'recommerce.repair.transition',
                'recommerce.repair.intake',
                'recommerce.repair.collection',
                'recommerce.warranty.manage',
            ],
            'recommerce.cohort.business_id' => 7,
            'recommerce.cohort.location_id' => 101,
            'recommerce.cohort.location_ids' => [101],
            'recommerce.cohort.variation_ids' => [303],
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
        $schema->create('users', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->unsignedInteger('business_id');
        });
        $schema->create('business_locations', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->unsignedInteger('business_id');
        });
        $schema->create('products', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->unsignedInteger('business_id');
            $table->string('name')->nullable();
            $table->unsignedInteger('warranty_id')->nullable();
            $table->boolean('enable_stock')->default(0);
        });
        $schema->create('variations', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->unsignedInteger('product_id');
            $table->decimal('sell_price_inc_tax', 22, 4)->nullable();
            $table->timestamp('deleted_at')->nullable();
        });
        $schema->create('contacts', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->unsignedInteger('business_id');
            $table->string('name')->nullable();
            $table->string('type', 20)->nullable();
            $table->timestamp('deleted_at')->nullable();
        });
        $schema->create('transactions', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('business_id');
            $table->unsignedInteger('location_id');
            $table->string('type');
            $table->string('status')->nullable();
            $table->string('sub_type')->nullable();
            $table->string('invoice_no')->nullable();
            $table->dateTime('transaction_date');
            $table->decimal('final_total', 22, 4);
            $table->unsignedInteger('contact_id')->nullable();
            $table->unsignedInteger('created_by')->nullable();
            $table->string('source')->nullable();
            $table->timestamps();
        });
        $schema->create('transaction_sell_lines', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('transaction_id');
            $table->unsignedInteger('product_id');
            $table->unsignedInteger('variation_id');
            $table->decimal('quantity', 22, 4);
            $table->decimal('unit_price', 22, 4)->nullable();
            $table->timestamps();
        });
        $schema->create('transaction_payments', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('transaction_id');
            $table->decimal('amount', 22, 4);
            $table->string('method', 20)->nullable();
            $table->timestamps();
        });
        $schema->create('warranties', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('business_id');
            $table->string('name');
            $table->unsignedInteger('duration');
            $table->string('duration_type');
            $table->timestamps();
        });

        DB::table('business')->insert(['id' => 7]);
        DB::table('users')->insert(['id' => 900, 'business_id' => 7]);
        DB::table('business_locations')->insert(['id' => 101, 'business_id' => 7]);
        DB::table('contacts')->insert(['id' => 405, 'business_id' => 7, 'name' => 'Fixture customer', 'type' => 'customer']);
        DB::table('products')->insert(['id' => 202, 'business_id' => 7, 'name' => 'Fixture product', 'warranty_id' => 1]);
        DB::table('variations')->insert(['id' => 303, 'product_id' => 202, 'sell_price_inc_tax' => 85]);
        DB::table('warranties')->insert(['business_id' => 7, 'name' => 'Service warranty', 'duration' => 6, 'duration_type' => 'months', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('transactions')->insert([
            'id' => 9001,
            'business_id' => 7,
            'location_id' => 101,
            'type' => 'sell',
            'status' => 'final',
            'sub_type' => 'recommerce_repair',
            'invoice_no' => 'SB-INV-0001',
            'transaction_date' => now()->subMonths(2),
            'final_total' => 100,
            'contact_id' => 405,
            'created_by' => 900,
            'source' => 'recommerce',
        ]);
        DB::table('transaction_sell_lines')->insert([
            'transaction_id' => 9001,
            'product_id' => 202,
            'variation_id' => 303,
            'quantity' => 1,
            'unit_price' => 100,
        ]);
        DB::table('transaction_payments')->insert([
            'transaction_id' => 9001,
            'amount' => 100,
            'method' => 'cash',
        ]);

        (require base_path('Modules/Recommerce/Database/Migrations/2026_08_27_000002_create_recommerce_alpha_tables.php'))->up();
        (require base_path('Modules/Recommerce/Database/Migrations/2026_08_28_000008_create_recommerce_repair_jobs.php'))->up();
        (require base_path('Modules/Recommerce/Database/Migrations/2026_08_28_000012_enhance_customer_repair_intake.php'))->up();
        (require base_path('Modules/Recommerce/Database/Migrations/2026_08_28_000006_create_recommerce_ownership_periods.php'))->up();
        (require base_path('Modules/Recommerce/Database/Migrations/2026_08_28_000007_create_recommerce_custody_periods.php'))->up();
        (require base_path('Modules/Recommerce/Database/Migrations/2026_08_29_000021_add_recommerce_repair_parent_job.php'))->up();
        (require base_path('Modules/Recommerce/Database/Migrations/2026_08_30_000024_create_recommerce_warranty_claims.php'))->up();

        DB::table('recommerce_devices')->insert([
            'id' => 11,
            'business_id' => 7,
            'device_uuid' => 'b4068cc7-0f29-4d22-8f45-4f9a29de1101',
            'device_code' => 'SB-DV-00000001-9',
            'ownership_kind' => 'CUSTOMER',
            'custody_kind' => 'LOCATION',
            'current_location_id' => 101,
            'current_owner_contact_id' => 405,
            'category_code' => 'MOBILE',
            'specifications_json' => json_encode(['brand' => 'Fixture', 'model' => 'X1']),
            'lifecycle_state' => 'CUSTOMER_CUSTODY',
            'stock_participation' => 'NONE',
            'product_id' => 202,
            'variation_id' => 303,
            'lock_version' => 1,
            'created_by' => 900,
            'updated_by' => 900,
        ]);
        Auth::setUser($this->user());
        DB::table('recommerce_repair_jobs')->insert([
            'id' => 31,
            'business_id' => 7,
            'location_id' => 101,
            'device_id' => 11,
            'contact_id' => 405,
            'job_uuid' => '11111111-1111-4111-8111-111111111111',
            'command_uuid' => '11111111-1111-4111-8111-111111111112',
            'job_code' => 'SB-RP-COVERAGE01',
            'job_type' => 'CUSTOMER_REPAIR',
            'state' => 'CLOSED',
            'priority' => 'NORMAL',
            'source_type' => 'POS_SELL',
            'source_id' => 9001,
            'lock_version' => 1,
            'opened_at' => now()->subDays(5),
            'closed_at' => now()->subDay(),
            'created_by' => 900,
            'updated_by' => 900,
        ]);
    }

    protected function tearDown(): void
    {
        DB::disconnect('sqlite');
        parent::tearDown();
    }

    public function test_repeat_claim_creates_an_incoverage_linked_job(): void
    {
        $claim = $this->service()->createClaim(
            $this->user(),
            $this->sourceJob(),
            '77777777-7777-4777-8777-777777777701',
            ['claimed_on' => now()->subDay()->toDateString(), 'covered_amount' => 30]
        );


        $repeatJob = RepairJob::query()->findOrFail($claim->repair_job_id);
        $this->assertSame('IN_COVERAGE', $claim->coverage_status);
        $this->assertSame(31, $repeatJob->parent_repair_job_id);
        $this->assertSame('COVERED', $claim->lines->first()->billing_treatment);
        $this->assertSame(1, DB::table('transaction_payments')->count());
    }

    public function test_partial_line_coverage_is_recorded_without_pos_writes(): void
    {
        $claim = $this->service()->createClaim(
            $this->user(),
            $this->sourceJob(),
            '77777777-7777-4777-8777-777777777702',
            ['claimed_on' => now()->subDay()->toDateString(), 'covered_amount' => 40]
        );

        $this->assertSame(2, $claim->lines->count());
        $this->assertEquals(40, $claim->lines->first()->amount);
        $this->assertEquals(60, $claim->lines->last()->amount);
        $this->assertSame(1, DB::table('transaction_payments')->count());
    }

    public function test_an_out_ofcoverage_claim_is_recorded_as_not_covered(): void
    {
        $claim = $this->service()->createClaim(
            $this->user(),
            $this->sourceJob(),
            '77777777-7777-4777-8777-777777777703',
            ['claimed_on' => now()->addMonths(7)->toDateString(), 'covered_amount' => 100]
        );

        $this->assertSame('NOT_COVERED', $claim->coverage_status);
        $this->assertNotNull($claim->coverage_end_at);
        $this->assertStringContainsString('outside', $claim->decision_reason);
        $this->assertNull($claim->repair_job_id);
    }

    public function test_missing_claim_date_is_not_evaluated(): void
    {
        $claim = $this->service()->createClaim($this->user(), $this->sourceJob(), '77777777-7777-4777-8777-777777777704', []);
        $this->assertSame('NOT_COVERED', $claim->coverage_status);
        $this->assertSame('CLAIM_DATE_REQUIRED', $claim->policy_snapshot_json['reason']);
    }

    public function test_missing_permission_is_denied(): void
    {
        config(['recommerce.permissions' => ['recommerce.repair.view', 'recommerce.repair.intake']]);
        $this->expectException(AuthorizationException::class);
        $this->service()->createClaim($this->user(), $this->sourceJob(), '77777777-7777-4777-8777-777777777705', ['claimed_on' => now()->subDay()->toDateString()]);
    }


    public function test_claim_http_route_creates_a_scoped_linked_claim(): void
    {
        (new \Modules\Recommerce\Providers\RouteServiceProvider(app()))->map();
        app('router')->getRoutes()->refreshNameLookups();
        app('url')->setRoutes(app('router')->getRoutes());

        $response = $this->actingAs($this->user())
            ->postJson('/recommerce/repair/SB-RP-COVERAGE01/warranty/claim', [
                'command_uuid' => '77777777-7777-4777-8777-777777777706',
                'claimed_on' => now()->subDay()->toDateString(),
                'covered_amount' => 30,
            ]);

        $response->assertCreated()
            ->assertJsonPath('status', 'WARRANTY_CLAIM_CREATED')
            ->assertJsonPath('coverage_status', 'IN_COVERAGE')
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('Referrer-Policy', 'no-referrer');

        $claim = \Modules\Recommerce\Entities\WarrantyClaim::where('command_uuid', '77777777-7777-4777-8777-777777777706')->firstOrFail();
        $this->assertNotNull($claim->repair_job_id);
        $this->assertSame('COVERED', $claim->lines->first()->billing_treatment);
    }

    /**
     * The route's failure paths were untested, which let the controller ship
     * without importing AuthorizationException or ValidationException: both
     * names resolved into the controller namespace, so `throw` fatalled and
     * `catch` never matched. A denied caller must get 404, not a 500.
     */
    public function test_route_denies_an_unpermitted_user_with_not_found(): void
    {
        $this->mapRecommerceRoutes();

        // Catalogued in config, but never granted to this user's role.
        config(['recommerce.permissions' => ['recommerce.repair.view']]);

        $this->actingAs($this->user())
            ->postJson('/recommerce/repair/SB-RP-COVERAGE01/warranty/claim', [
                'command_uuid' => '77777777-7777-4777-8777-777777777711',
                'claimed_on' => now()->subDay()->toDateString(),
            ])
            ->assertNotFound();
    }

    /**
     * Invalid input must return the masked message rather than Laravel's
     * field-level validation payload.
     */
    public function test_route_masks_invalid_input(): void
    {
        $this->mapRecommerceRoutes();

        $response = $this->actingAs($this->user())
            ->postJson('/recommerce/repair/SB-RP-COVERAGE01/warranty/claim', [
                'command_uuid' => 'not-a-uuid',
                'claimed_on' => 'not-a-date',
            ]);

        $response->assertStatus(422)
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('Referrer-Policy', 'no-referrer');
        $this->assertSame('A warranty claim could not be created from this job.', $response->json('message'));
        $this->assertNull($response->json('errors'), 'Field-level validation detail must not be exposed.');
    }

    /**
     * `claimed_on` is date-only, so it parses to midnight, while the sale
     * carries a full timestamp. Comparing them directly rejected a device sold
     * at 14:30 and brought back the same day as "outside the recorded warranty
     * term". Coverage must include the day of sale.
     */
    public function test_a_claim_on_the_day_of_sale_is_covered(): void
    {
        $saleAt = now()->subMonth()->setTime(14, 30);
        DB::table('transactions')->where('id', 9001)->update(['transaction_date' => $saleAt]);

        $decision = $this->service()->decision(
            $this->sourceJob()->fresh(['device']),
            ['claimed_on' => $saleAt->toDateString()]
        );

        $this->assertSame('IN_COVERAGE', $decision['coverage_status']);
        // The exact sale timestamp stays the recorded evidence.
        $this->assertSame($saleAt->toDateTimeString(), $decision['coverage_start_at']->toDateTimeString());
    }

    /** A claim genuinely before the sale is still refused. */
    public function test_a_claim_before_the_sale_day_is_not_covered(): void
    {
        $saleAt = now()->subMonth()->setTime(14, 30);
        DB::table('transactions')->where('id', 9001)->update(['transaction_date' => $saleAt]);

        $decision = $this->service()->decision(
            $this->sourceJob()->fresh(['device']),
            ['claimed_on' => $saleAt->copy()->subDay()->toDateString()]
        );

        $this->assertSame('NOT_COVERED', $decision['coverage_status']);
        $this->assertSame('The claimed_on date is outside the recorded warranty term.', $decision['decision_reason']);
    }

    /**
     * A sale can legitimately carry more than one line for the same variation.
     * The lookup had no ORDER BY, so the database was free to return either
     * line's policy and the recorded coverage term could differ run to run.
     * This pins the selection as reproducible (lowest sell-line id).
     *
     * NOTE: which line *should* win is a product decision that has not been
     * made; this test documents determinism, not a policy.
     */
    public function test_warranty_selection_is_deterministic_across_duplicate_sell_lines(): void
    {
        DB::table('warranties')->insert(['id' => 2, 'business_id' => 7, 'name' => 'Extended warranty',
            'duration' => 24, 'duration_type' => 'months', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('products')->insert(['id' => 203, 'business_id' => 7, 'name' => 'Second product', 'warranty_id' => 2]);
        DB::table('transaction_sell_lines')->insert([
            'transaction_id' => 9001, 'product_id' => 203, 'variation_id' => 303, 'quantity' => 1,
        ]);

        $first = $this->service()->decision($this->sourceJob()->fresh(['device']),
            ['claimed_on' => now()->subDay()->toDateString()]);
        $second = $this->service()->decision($this->sourceJob()->fresh(['device']),
            ['claimed_on' => now()->subDay()->toDateString()]);

        $this->assertSame($first['warranty_id'], $second['warranty_id'], 'Policy selection must be reproducible.');
        $this->assertSame(1, (int) $first['warranty_id'], 'The earliest matching sell line supplies the policy.');
        $this->assertSame(6, $first['policy_snapshot_json']['duration']);
    }

    /**
     * `claimed_on` and `policy_name` are dedicated columns on the claims table,
     * but the service only ever wrote them into the JSON evidence, so every row
     * stored NULL and reporting could not query either without unpacking JSON.
     */
    public function test_claim_persists_queryable_date_and_policy_columns(): void
    {
        $claimedOn = now()->subDay()->toDateString();

        $claim = $this->service()->createClaim(
            $this->user(),
            $this->sourceJob(),
            '77777777-7777-4777-8777-777777777722',
            ['claimed_on' => $claimedOn, 'covered_amount' => 10]
        );

        $row = DB::table('recommerce_warranty_claims')->where('id', $claim->id)->first();
        $this->assertSame($claimedOn, substr((string) $row->claimed_on, 0, 10));
        $this->assertSame('Service warranty', $row->policy_name);
        // Not fabricated: policy versioning is still nominal upstream.
        $this->assertNull($row->policy_version);
    }

    protected function mapRecommerceRoutes(): void
    {
        (new \Modules\Recommerce\Providers\RouteServiceProvider(app()))->map();
        app('router')->getRoutes()->refreshNameLookups();
        app('url')->setRoutes(app('router')->getRoutes());
    }

    protected function service(): WarrantyClaimService
    {
        return new WarrantyClaimService(new AuthorizationGate(new CohortPolicy()), new CohortPolicy());
    }

    protected function sourceJob(): RepairJob
    {
        return RepairJob::query()->where('job_code', 'SB-RP-COVERAGE01')->firstOrFail();
    }

    protected function user(): User
    {
        $user = new class extends User {
            public function can($ability, $arguments = []): bool
            {
                return in_array($ability, config('recommerce.permissions', []), true);
            }

            public function permitted_locations($business_id = null)
            {
                return [101];
            }
        };
        $user->id = 900;
        $user->business_id = 7;

        return $user;
    }
}
