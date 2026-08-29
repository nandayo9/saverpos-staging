<?php

namespace Tests\Feature;

use App\Transaction;
use App\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;
use Modules\Recommerce\Entities\RepairJob;
use Modules\Recommerce\Entities\RepairPartUsage;
use Modules\Recommerce\Services\RepairCollectionService;
use Modules\Recommerce\Support\AuthorizationGate;
use Modules\Recommerce\Support\CohortPolicy;
use Modules\Recommerce\Services\DeviceEventRecorder;
use Tests\TestCase;

class RecommerceRepairCollectionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'database.connections.sqlite.foreign_key_constraints' => true,
            'app.key' => 'base64:'.base64_encode(str_repeat('a', 32)),
            'recommerce.enabled' => true,
            'recommerce.writes_enabled' => true,
            'recommerce.permissions' => [
                'recommerce.repair.view',
                'recommerce.repair.transition',
                'recommerce.repair.intake',
                'recommerce.repair.collection',
                'recommerce.repair.collection.override',
                'recommerce.repair.billing',
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
            $table->string('type')->nullable();
            $table->string('status')->nullable();
            $table->string('payment_status')->nullable();
            $table->unsignedInteger('contact_id')->nullable();
            $table->string('invoice_no')->nullable();
            $table->string('ref_no')->nullable();
            $table->dateTime('transaction_date')->nullable();
            $table->decimal('final_total', 22, 4)->nullable();
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
            $table->decimal('unit_price_inc_tax', 22, 4)->nullable();
            $table->timestamps();
        });
        $schema->create('transaction_payments', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('transaction_id');
            $table->decimal('amount', 22, 4)->default(0);
            $table->string('method', 20)->nullable();
            $table->timestamps();
        });

        DB::table('business')->insert(['id' => 7]);
        DB::table('users')->insert(['id' => 900, 'business_id' => 7]);
        DB::table('business_locations')->insert(['id' => 101, 'business_id' => 7]);
        DB::table('contacts')->insert(['id' => 405, 'business_id' => 7, 'name' => 'Fixture customer', 'type' => 'customer']);
        DB::table('products')->insert(['id' => 202, 'business_id' => 7, 'name' => 'Replacement screen', 'enable_stock' => 1]);
        DB::table('variations')->insert(['id' => 303, 'product_id' => 202, 'sell_price_inc_tax' => 85]);

        (require base_path('Modules/Recommerce/Database/Migrations/2026_08_27_000002_create_recommerce_alpha_tables.php'))->up();
        (require base_path('Modules/Recommerce/Database/Migrations/2026_08_28_000004_harden_recommerce_event_identity.php'))->up();
        (require base_path('Modules/Recommerce/Database/Migrations/2026_08_28_000008_create_recommerce_repair_jobs.php'))->up();
        (require base_path('Modules/Recommerce/Database/Migrations/2026_08_28_000010_create_recommerce_repair_parts.php'))->up();
        (require base_path('Modules/Recommerce/Database/Migrations/2026_08_28_000011_create_recommerce_repair_cost_entries.php'))->up();
        (require base_path('Modules/Recommerce/Database/Migrations/2026_08_28_000012_enhance_customer_repair_intake.php'))->up();
        (require base_path('Modules/Recommerce/Database/Migrations/2026_08_28_000006_create_recommerce_ownership_periods.php'))->up();
        (require base_path('Modules/Recommerce/Database/Migrations/2026_08_28_000007_create_recommerce_custody_periods.php'))->up();
        (require base_path('Modules/Recommerce/Database/Migrations/2026_08_29_000019_register_recommerce_repair_billing_permission.php'))->up();
        (require base_path('Modules/Recommerce/Database/Migrations/2026_08_29_000020_register_recommerce_repair_collection_permission.php'))->up();
        (require base_path('Modules/Recommerce/Database/Migrations/2026_08_29_000021_add_recommerce_repair_parent_job.php'))->up();

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
            'lock_version' => 1,
            'created_by' => 900,
            'updated_by' => 900,
        ]);
        DB::table('recommerce_repair_jobs')->insert([
            'id' => 31,
            'business_id' => 7,
            'location_id' => 101,
            'device_id' => 11,
            'contact_id' => 405,
            'job_uuid' => '11111111-1111-4111-8111-111111111111',
            'command_uuid' => '11111111-1111-4111-8111-111111111112',
            'job_code' => 'SB-RP-COLLECT01',
            'job_type' => 'CUSTOMER_REPAIR',
            'state' => 'READY',
            'priority' => 'NORMAL',
            'lock_version' => 1,
            'opened_at' => now(),
            'created_by' => 900,
            'updated_by' => 900,
        ]);
        DB::table('recommerce_repair_state_transitions')->insert([
            'business_id' => 7,
            'location_id' => 101,
            'repair_job_id' => 31,
            'transition_uuid' => 'aaaa1111-1111-4111-8111-111111111111',
            'from_state' => 'QC',
            'to_state' => 'READY',
            'evidence_json' => json_encode(['qc_passed' => true, 'resolution_code' => 'COMPLETED']),
            'actor_id' => 900,
            'occurred_at' => now(),
        ]);

        Auth::setUser($this->authorizedUser());
    }

    protected function tearDown(): void
    {
        DB::disconnect('sqlite');

        parent::tearDown();
    }

    public function test_paid_sale_collects_and_closes_job_with_custody_handover(): void
    {
        $service = $this->collectionService();
        $job = $this->job();
        $sale = $this->finalizedSale(125.0, 125.0);
        $this->linkJobToSale($job, $sale);

        $closed = $service->collect($this->authorizedUser(), $job, [
            'collector_name' => 'Fixture collector',
            'collector_phone' => '0123',
        ]);

        $this->assertSame('CLOSED', $closed->state);
        $this->assertNotNull($closed->closed_at);
        $this->assertSame('CUSTOMER', $closed->device->custody_kind);
        $this->assertNull($closed->device->current_location_id);
        $this->assertSame(1, DB::table('recommerce_device_movements')->where('movement_type', 'CUSTOMER_REPAIR_COLLECTED')->count());
        $this->assertSame(1, DB::table('recommerce_device_events')->where('event_type', 'CUSTOMER_REPAIR_COLLECTED')->count());
    }

    public function test_unpaid_sale_needs_authorized_override_and_records_it(): void
    {
        $service = $this->collectionService();
        $job = $this->job();
        $sale = $this->finalizedSale(125.0, 50.0);
        $this->linkJobToSale($job, $sale);

        try {
            $service->collect($this->authorizedUser(), $job, ['collector_name' => 'Collector A']);
            $this->fail('Expected an unpaid sale to block collection.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('recorded override', $exception->getMessage());
        }

        $this->assertSame('READY', $job->fresh()->state);

        try {
            config(['recommerce.permissions' => ['recommerce.repair.view', 'recommerce.repair.collection']]);
            $service->collect($this->authorizedUser(), $job, ['collector_name' => 'Collector B'], 'Manager approved unpaid handover.');
            $this->fail('Expected an unauthorized override to be denied.');
        } catch (AuthorizationException $exception) {
            $this->assertTrue(true);
        }
        config(['recommerce.permissions' => $this->fullPermissions()]);

        $closed = $service->collect($this->authorizedUser(), $job, ['collector_name' => 'Collector B'], 'Manager-approved unpaid handover.');
        $this->assertSame('CLOSED', $closed->state);
        $this->assertSame('CLOSED', $job->fresh()->state);
    }

    public function test_a_non_collector_is_denied_collection(): void
    {
        config(['recommerce.permissions' => ['recommerce.repair.view']]);
        $service = $this->collectionService();

        $this->expectException(AuthorizationException::class);
        $service->collect($this->authorizedUser(), $this->job(), ['collector_name' => 'Should not run']);
    }

    public function test_missing_qc_outcome_blocks_collection(): void
    {
        $service = $this->collectionService();
        $job = $this->job();
        DB::table('recommerce_repair_state_transitions')->update([
            'evidence_json' => json_encode(['qc_passed' => false]),
            'from_state' => 'QC',
            'to_state' => 'IN_REPAIR',
        ]);

        try {
            $service->collect($this->authorizedUser(), $job, ['collector_name' => 'Collector C']);
            $this->fail('Expected a job without a QC pass to block collection.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('recorded QC outcome', $exception->getMessage());
        }

        $this->assertSame('READY', $job->fresh()->state);
    }

    public function test_pending_billed_parts_block_collection(): void
    {
        $service = $this->collectionService();
        $job = $this->job();
        $this->pendingPart();

        try {
            $service->collect($this->authorizedUser(), $job, ['collector_name' => 'Collector C']);
            $this->fail('Expected unbilled installed parts to block collection.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('billed before collection', $exception->getMessage());
        }

        $this->assertSame('READY', $job->fresh()->state);
    }

    public function test_summary_reads_the_pos_balance_only(): void
    {
        $service = $this->collectionService();
        $job = $this->job();
        $sale = $this->finalizedSale(125.0, 50.0);
        $this->linkJobToSale($job, $sale);

        $summary = $service->summary($this->authorizedUser(), $job);
        $this->assertSame($sale->id, $summary['sale_transaction_id']);
        $this->assertSame(125.0, $summary['billed_total']);
        $this->assertSame(50.0, $summary['paid_amount']);
        $this->assertSame(75.0, $summary['outstanding_amount']);

        // The module never invents payments or ledgers; the POS rows are untouched.
        $this->assertSame(1, DB::table('transaction_payments')->count());
    }

    public function test_repeat_visit_creates_a_linked_job_for_the_closed_one(): void
    {
        $service = $this->collectionService();
        $job = $this->job();
        $sale = $this->finalizedSale(125.0, 125.0);
        $this->linkJobToSale($job, $sale);
        $service->collect($this->authorizedUser(), $job, ['collector_name' => 'Collector B']);

        $repeat = $service->startRepeat($this->authorizedUser(), $job->fresh(), '77777777-7777-4777-8777-777777777771');

        $this->assertSame($job->id, $repeat->parent_repair_job_id);
        $this->assertSame('CUSTOMER_REPAIR', $repeat->job_type);
        $this->assertSame($job->device_id, $repeat->device_id);
        $this->assertSame($job->contact_id, $repeat->contact_id);
        $this->assertSame('LOCATION', $repeat->device->custody_kind);
        $this->assertSame('CLOSED', $job->fresh()->state);
    }

    protected function collectionService(): RepairCollectionService
    {
        return new RepairCollectionService(
            new AuthorizationGate(new CohortPolicy()),
            new DeviceEventRecorder()
        );
    }

    protected function job(): RepairJob
    {
        return RepairJob::query()->where('job_code', 'SB-RP-COLLECT01')->firstOrFail();
    }

    protected function finalizedSale(float $finalTotal, float $paid): Transaction
    {
        $sale = Transaction::create([
            'business_id' => 7,
            'location_id' => 101,
            'type' => 'sell',
            'status' => 'final',
            'payment_status' => 'paid',
            'contact_id' => 405,
            'transaction_date' => now(),
            'final_total' => $finalTotal,
            'created_by' => 900,
            'source' => 'recommerce',
            'sub_type' => 'recommerce_repair',
        ]);
        DB::table('transaction_payments')->insert([
            'transaction_id' => $sale->id,
            'amount' => $paid,
            'method' => 'cash',
        ]);

        return $sale;
    }

    protected function pendingPart(): void
    {
        DB::table('recommerce_repair_part_reservations')->insert([
            'id' => 61,
            'business_id' => 7,
            'location_id' => 101,
            'repair_job_id' => $this->job()->id,
            'product_id' => 202,
            'variation_id' => 303,
            'command_uuid' => '55555555-5555-4555-8555-555555555501',
            'quantity' => 1,
            'status' => 'RESERVED',
            'reserved_at' => now(),
            'reserved_by' => 900,
        ]);
        DB::table('recommerce_repair_part_usages')->insert([
            'business_id' => 7,
            'location_id' => 101,
            'repair_job_id' => $this->job()->id,
            'reservation_id' => 61,
            'product_id' => 202,
            'variation_id' => 303,
            'usage_uuid' => '55555555-5555-4555-8555-555555555561',
            'command_uuid' => '55555555-5555-4555-8555-555555555562',
            'consumption_path' => 'CUSTOMER',
            'status' => 'INSTALLED_PENDING_BILLING',
            'quantity' => 1,
            'issued_at' => now(),
            'installed_at' => now(),
        ]);
    }

    protected function linkJobToSale(RepairJob $job, Transaction $sale): void
    {
        $job->source_type = 'POS_SELL';
        $job->source_id = $sale->id;
        $job->save();
    }

    /**
     * @return array<int, string>
     */
    protected function fullPermissions(): array
    {
        return [
            'recommerce.repair.view',
            'recommerce.repair.view_cost',
            'recommerce.repair.intake',
            'recommerce.repair.transition',
            'recommerce.repair.collection',
            'recommerce.repair.collection.override',
            'recommerce.repair.billing',
        ];
    }

    protected function authorizedUser(): User
    {
        $user = new class extends User
        {
            public function can($ability, $arguments = []): bool
            {
                return in_array($ability, [
                    'recommerce.repair.view',
                    'recommerce.repair.view_cost',
                    'recommerce.repair.intake',
                    'recommerce.repair.transition',
                    'recommerce.repair.collection',
                    'recommerce.repair.collection.override',
                    'recommerce.repair.billing',
                ], true);
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