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
use Modules\Recommerce\Services\RepairBillingService;
use Modules\Recommerce\Services\RepairPartService;
use Modules\Recommerce\Support\AuthorizationGate;
use Modules\Recommerce\Support\CohortPolicy;
use Tests\TestCase;

class RecommerceRepairBillingTest extends TestCase
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
                'recommerce.repair.view_cost',
                'recommerce.repair.parts.resolve',
                'recommerce.repair.billing',
            ],
            'recommerce.cohort.business_id' => 7,
            'recommerce.cohort.location_id' => 101,
            'recommerce.cohort.location_ids' => [101],
            'recommerce.cohort.variation_ids' => [303, 305],
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
            $table->unsignedInteger('return_parent_id')->nullable();
            $table->string('invoice_no')->nullable();
            $table->string('ref_no')->nullable();
            $table->dateTime('transaction_date')->nullable();
            $table->decimal('final_total', 22, 4)->nullable();
            $table->unsignedInteger('created_by')->nullable();
            $table->decimal('exchange_rate', 22, 4)->nullable();
            $table->string('source')->nullable();
            $table->string('sub_type', 20)->nullable();
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
        $schema->create('variation_location_details', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->unsignedInteger('product_id');
            $table->unsignedInteger('location_id');
            $table->unsignedInteger('variation_id');
            $table->decimal('qty_available', 22, 4);
        });

        DB::table('business')->insert(['id' => 7]);
        DB::table('users')->insert(['id' => 900, 'business_id' => 7]);
        DB::table('business_locations')->insert(['id' => 101, 'business_id' => 7]);
        DB::table('contacts')->insert([
            ['id' => 405, 'business_id' => 7, 'name' => 'Fixture customer'],
            ['id' => 406, 'business_id' => 7, 'name' => 'Other customer'],
        ]);
        DB::table('products')->insert([
            ['id' => 202, 'business_id' => 7, 'name' => 'Replacement screen part', 'enable_stock' => 1],
            ['id' => 203, 'business_id' => 7, 'name' => 'Screen unlock service', 'enable_stock' => 0],
        ]);
        DB::table('variations')->insert([
            ['id' => 303, 'product_id' => 202, 'sell_price_inc_tax' => 85],
            ['id' => 305, 'product_id' => 203, 'sell_price_inc_tax' => 40],
        ]);

        (require base_path('Modules/Recommerce/Database/Migrations/2026_08_27_000002_create_recommerce_alpha_tables.php'))->up();
        (require base_path('Modules/Recommerce/Database/Migrations/2026_08_28_000008_create_recommerce_repair_jobs.php'))->up();
        (require base_path('Modules/Recommerce/Database/Migrations/2026_08_28_000010_create_recommerce_repair_parts.php'))->up();
        (require base_path('Modules/Recommerce/Database/Migrations/2026_08_28_000012_enhance_customer_repair_intake.php'))->up();
        (require base_path('Modules/Recommerce/Database/Migrations/2026_08_29_000017_create_recommerce_repair_quotes.php'))->up();

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
            'job_code' => 'SB-RP-BILLING01',
            'job_type' => 'CUSTOMER_REPAIR',
            'state' => 'RECEIVED',
            'priority' => 'NORMAL',
            'lock_version' => 1,
            'opened_at' => now(),
            'created_by' => 900,
            'updated_by' => 900,
        ]);

        Auth::setUser($this->authorizedUser());
    }

    protected function tearDown(): void
    {
        DB::disconnect('sqlite');

        parent::tearDown();
    }

    public function test_projection_lists_pending_parts_and_approved_service_lines(): void
    {
        $service = $this->billingService();
        $job = $this->pendingPartJob();
        $this->approveQuoteWithServiceLine($job);

        $projection = $service->project($this->authorizedUser(), $job->fresh());

        $this->assertCount(1, $projection['parts']);
        $this->assertSame(303, $projection['parts'][0]['variation_id']);
        $this->assertSame(1.0, $projection['parts'][0]['quantity']);
        $this->assertSame(85.0, $projection['parts'][0]['unit_price']);
        $this->assertCount(1, $projection['services']);
        $this->assertSame('Screen unlock service', $projection['services'][0]['description']);
        $this->assertSame(40.0, $projection['services'][0]['unit_amount']);
        $this->assertSame(305, $projection['services'][0]['pos_variation_id']);

        // The projection is read-only: no usage or job state changed.
        $this->assertSame('INSTALLED_PENDING_BILLING', RepairPartUsage::query()->value('status'));
        $this->assertNull(RepairJob::query()->value('source_id'));
    }

    public function test_link_consumes_each_pending_part_once_and_stores_the_pos_linkage(): void
    {
        $service = $this->billingService();
        $job = $this->pendingPartJob();
        $sale = $this->finalizedSale();

        $billed = $service->linkSale($this->authorizedUser(), $job, '66666666-6666-4666-8666-666666666661', $sale->id);

        $this->assertSame(RepairBillingService::JOB_SOURCE_TYPE, $billed->source_type);
        $this->assertSame($sale->id, (int) $billed->source_id);
        $usage = RepairPartUsage::query()->firstOrFail();
        $this->assertSame('CONSUMED', $usage->status);
        $this->assertSame('SALE', $usage->source_type);
        $this->assertSame($sale->id, (int) $usage->source_transaction_id);
        $this->assertNotNull($usage->source_line_id);
        $this->assertNotNull($usage->resolved_at);
        $this->assertSame('CONSUMED', $usage->reservation->status);

        $replayed = $service->linkSale(
            $this->authorizedUser(),
            $job->fresh(),
            '66666666-6666-4666-8666-666666666661',
            $sale->id
        );
        $this->assertSame($billed->getKey(), $replayed->getKey());
        $this->assertSame(1, RepairPartUsage::query()->where('status', 'CONSUMED')->count());
    }

    public function test_uncovered_pending_part_blocks_billing_without_partial_state(): void
    {
        $service = $this->billingService();
        $job = $this->pendingPartJob();
        $sale = $this->finalizedSale([[202, 303, 0.5, 42.5]]);

        try {
            $service->linkSale($this->authorizedUser(), $job, '77777777-7777-4777-8777-777777777771', $sale->id);
            $this->fail('Expected an installed-unbilled reconciliation failure.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('does not cover all installed pending parts', $exception->getMessage());
        }

        $usage = RepairPartUsage::query()->firstOrFail();
        $this->assertSame('INSTALLED_PENDING_BILLING', $usage->status);
        $this->assertNull($usage->source_transaction_id);
        $this->assertNull($job->fresh()->source_id);
    }

    public function test_link_rejects_draft_or_out_of_scope_sales(): void
    {
        $service = $this->billingService();
        $job = $this->pendingPartJob();
        $this->finalizedSale();

        $draft = Transaction::create([
            'business_id' => 7, 'location_id' => 101, 'type' => 'sell',
            'status' => 'draft', 'contact_id' => 405, 'transaction_date' => now(),
            'payment_status' => 'due', 'created_by' => 900, 'source' => 'test',
        ]);
        $wrongCustomer = Transaction::create([
            'business_id' => 7, 'location_id' => 101, 'type' => 'sell',
            'status' => 'final', 'contact_id' => 406, 'transaction_date' => now(),
            'payment_status' => 'due', 'created_by' => 900, 'source' => 'test',
        ]);
        $wrongLocation = Transaction::create([
            'business_id' => 7, 'location_id' => 102, 'type' => 'sell',
            'status' => 'final', 'contact_id' => 405, 'transaction_date' => now(),
            'payment_status' => 'due', 'created_by' => 900, 'source' => 'test',
        ]);

        foreach ([$draft, $wrongCustomer, $wrongLocation] as $invalid) {
            try {
                $service->linkSale($this->authorizedUser(), $job, '88888888-8888-4888-8888-888888888881', $invalid->id);
                $this->fail('Expected the non-finalized or out-of-scope sale to be rejected.');
            } catch (LogicException $exception) {
                $this->assertStringContainsString('finalized POS sale', $exception->getMessage());
            }
        }

        $this->assertSame('INSTALLED_PENDING_BILLING', RepairPartUsage::query()->value('status'));
        $this->assertNull($job->fresh()->source_id);
    }

    public function test_release_reverts_billed_state_and_survives_retry(): void
    {
        $service = $this->billingService();
        $job = $this->pendingPartJob();
        $sale = $this->finalizedSale();
        $service->linkSale($this->authorizedUser(), $job, '66666666-6666-4666-8666-666666666661', $sale->id);

        $released = $service->releaseSale($this->authorizedUser(), $job, $sale->id, 'POS sale was returned.');
        $this->assertSame(1, $released);

        $usage = RepairPartUsage::query()->firstOrFail();
        $this->assertSame('INSTALLED_PENDING_BILLING', $usage->status);
        $this->assertNull($usage->source_transaction_id);
        $this->assertNull($usage->source_line_id);
        $this->assertNull($usage->resolved_at);
        $this->assertSame('ISSUED', $usage->reservation->status);
        $this->assertNull($job->fresh()->source_id);

        $second = $service->releaseSale($this->authorizedUser(), $job->fresh(), $sale->id, 'Repeat is safe.');
        $this->assertSame(0, $second);

        $this->assertSame('INSTALLED_PENDING_BILLING', RepairPartUsage::query()->value('status'));
    }

    public function test_missing_billing_permission_is_denied(): void
    {
        config(['recommerce.permissions' => ['recommerce.repair.view']]);
        $service = $this->billingService();

        $this->expectException(AuthorizationException::class);
        $service->project($this->authorizedUser(), $this->job());
    }


    protected function job(): RepairJob
    {
        $job = RepairJob::query()->where('job_code', 'SB-RP-BILLING01')->firstOrFail();
        $this->assertNotNull($job->device);

        return $job;
    }

    /**
     * Insert one installed-pending-billing customer part usage for the fixture job.
     */
    protected function pendingPartJob(): RepairJob
    {
        $job = $this->job();
        DB::table('recommerce_repair_part_reservations')->insert([
            'id' => 61,
            'business_id' => 7,
            'location_id' => 101,
            'repair_job_id' => $job->id,
            'product_id' => 202,
            'variation_id' => 303,
            'command_uuid' => '55555555-5555-4555-8555-555555555501',
            'quantity' => 1,
            'status' => 'ISSUED',
            'reserved_at' => now(),
            'reserved_by' => 900,
        ]);
        DB::table('recommerce_repair_part_usages')->insert([
            'id' => 62,
            'business_id' => 7,
            'location_id' => 101,
            'repair_job_id' => $job->id,
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

        return $job;
    }

    /**
     * Create a finalized POS sale whose lines are described by
     * [variation_id, quantity, unit_price] triples.
     * @param array<int, array{0:int,1:int,2:float,3:float}> $lines [productId, variationId, quantity, priceIncTax]
     */
    protected function finalizedSale(array $lines = [[202, 303, 1.0, 85.0], [203, 305, 1.0, 40.0]]): Transaction
    {
        $sale = Transaction::create([
            'business_id' => 7,
            'location_id' => 101,
            'type' => 'sell',
            'status' => 'final',
            'payment_status' => 'paid',
            'contact_id' => 405,
            'transaction_date' => now(),
            'final_total' => 0,
            'created_by' => 900,
            'source' => 'recommerce',
            'sub_type' => 'recommerce_repair',
        ]);
        foreach ($lines as [$productId, $variationId, $quantity, $unitPrice]) {
            $sale->sell_lines()->create([
                'product_id' => $productId,
                'variation_id' => $variationId,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'unit_price_inc_tax' => $unitPrice,
            ]);
        }

        return $sale;
    }

    protected function approveQuoteWithServiceLine(RepairJob $job): void
    {
        DB::table('recommerce_repair_quotes')->insert([
            'id' => 80,
            'business_id' => 7,
            'location_id' => 101,
            'repair_job_id' => $job->id,
            'quote_uuid' => '99999999-9999-4999-8999-999999999991',
            'command_uuid' => '99999999-9999-4999-8999-999999999992',
            'version_number' => 1,
            'status' => 'APPROVED',
            'summary' => 'Fixture repair scope',
            'total_amount' => 40,
            'created_by' => 900,
            'updated_by' => 900,
        ]);
        DB::table('recommerce_repair_quote_lines')->insert([
            'quote_id' => 80,
            'business_id' => 7,
            'location_id' => 101,
            'line_type' => 'SERVICE',
            'source_type' => 'POS_VARIATION',
            'source_id' => 305,
            'variation_id' => null,
            'description' => 'Screen unlock service',
            'quantity' => 1,
            'unit_amount' => 40,
            'tax_amount' => 0,
            'line_total_amount' => 40,
            'sort_order' => 0,
        ]);
    }

    protected function billingService(): RepairBillingService
    {
        return new RepairBillingService(new AuthorizationGate(new CohortPolicy()));
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
                    'recommerce.repair.transition',
                    'recommerce.repair.parts.resolve',
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
