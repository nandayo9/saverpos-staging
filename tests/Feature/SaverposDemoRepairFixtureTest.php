<?php

namespace Tests\Feature;

use Database\Seeders\SaverposDemoRepairFixture;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Recommerce\Entities\Device;
use Modules\Recommerce\Entities\RepairJob;
use Modules\Recommerce\Support\Identity\DeviceCode;
use Modules\Recommerce\Support\RepairJobStateMachine;
use Tests\TestCase;

class SaverposDemoRepairFixtureTest extends TestCase
{
    private const BUSINESS_ID = 7;
    private const LOCATION_ID = 101;
    private const USER_ID = 900;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'database.connections.sqlite.foreign_key_constraints' => true,
            'app.key' => 'base64:'.base64_encode(str_repeat('a', 32)),
        ]);

        DB::purge('sqlite');
        $schema = Schema::connection('sqlite');

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
        // Core tables the Recommerce foreign keys point at. A customer-owned
        // repair device carries none of them, but SQLite still requires the
        // referenced tables to exist before the insert is accepted.
        $schema->create('products', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->unsignedInteger('business_id');
        });
        $schema->create('variations', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->unsignedInteger('product_id');
        });
        $schema->create('transactions', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('business_id');
        });
        $schema->create('purchase_lines', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('transaction_id');
        });
        $schema->create('contacts', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('business_id');
            $table->string('name')->nullable();
            $table->string('contact_id')->nullable();
            $table->string('type', 20)->nullable();
            $table->timestamp('deleted_at')->nullable();
        });

        DB::table('business')->insert(['id' => self::BUSINESS_ID]);
        DB::table('users')->insert(['id' => self::USER_ID, 'business_id' => self::BUSINESS_ID]);
        DB::table('business_locations')->insert(['id' => self::LOCATION_ID, 'business_id' => self::BUSINESS_ID]);

        (require base_path('Modules/Recommerce/Database/Migrations/2026_08_27_000002_create_recommerce_alpha_tables.php'))->up();
        (require base_path('Modules/Recommerce/Database/Migrations/2026_08_28_000004_harden_recommerce_event_identity.php'))->up();
        (require base_path('Modules/Recommerce/Database/Migrations/2026_08_28_000006_create_recommerce_ownership_periods.php'))->up();
        (require base_path('Modules/Recommerce/Database/Migrations/2026_08_28_000007_create_recommerce_custody_periods.php'))->up();
        (require base_path('Modules/Recommerce/Database/Migrations/2026_08_28_000008_create_recommerce_repair_jobs.php'))->up();
        (require base_path('Modules/Recommerce/Database/Migrations/2026_08_28_000012_enhance_customer_repair_intake.php'))->up();
        (require base_path('Modules/Recommerce/Database/Migrations/2026_08_29_000017_create_recommerce_repair_quotes.php'))->up();
        (require base_path('Modules/Recommerce/Database/Migrations/2026_08_29_000021_add_recommerce_repair_parent_job.php'))->up();
    }

    protected function tearDown(): void
    {
        DB::disconnect('sqlite');

        parent::tearDown();
    }

    public function test_fixture_opens_one_customer_repair_job_per_demo_customer(): void
    {
        $this->demoCustomers();

        $created = $this->apply();

        $this->assertSame(4, $created);
        $jobs = RepairJob::query()->get();
        $this->assertCount(4, $jobs);

        foreach ($jobs as $job) {
            $this->assertSame(RepairJobStateMachine::TYPE_CUSTOMER_REPAIR, $job->job_type);
            $this->assertSame(self::BUSINESS_ID, (int) $job->business_id);
            $this->assertSame(self::LOCATION_ID, (int) $job->location_id);
            $this->assertNotNull($job->contact_id);
            $this->assertNotNull($job->reported_fault);
            $this->assertSame('saverpos_demo_fixture', $job->intake_snapshot_json['source']);
        }

        // Every demo customer gets exactly one job, so the queue is not four
        // rows against a single contact.
        $this->assertSame(
            [1, 2, 3, 4],
            RepairJob::query()->pluck('contact_id')->map('intval')->sort()->values()->all()
        );
    }

    public function test_fixture_devices_are_customer_owned_and_outside_stock(): void
    {
        $this->demoCustomers();

        $this->apply();

        $devices = Device::query()->get();
        $this->assertCount(4, $devices);

        foreach ($devices as $device) {
            $this->assertSame('CUSTOMER', $device->ownership_kind);
            // Anything participating in stock would move the tracked
            // reconciliation counts the demo estate is verified against.
            $this->assertSame('NONE', $device->stock_participation);
            $this->assertSame(self::LOCATION_ID, (int) $device->current_location_id);
            $this->assertNull($device->variation_id);
            $this->assertTrue(DeviceCode::isValid((string) $device->device_code));
            $this->assertSame(DeviceCode::forDeviceId((int) $device->id), $device->device_code);
        }

        $this->assertSame(4, DB::table('recommerce_device_ownership_periods')->where('owner_kind', 'CONTACT')->count());
        $this->assertSame(4, DB::table('recommerce_device_custody_periods')->count());
        $this->assertSame(4, DB::table('recommerce_device_movements')->where('movement_type', 'CUSTOMER_REPAIR_INTAKE')->count());
        $this->assertSame(4, DB::table('recommerce_device_events')->where('event_type', 'CUSTOMER_REPAIR_INTAKE')->count());
    }

    public function test_fixture_spreads_jobs_across_the_queue_states(): void
    {
        $this->demoCustomers();

        $this->apply();

        $this->assertSame(
            [
                RepairJobStateMachine::STATE_RECEIVED,
                RepairJobStateMachine::STATE_DIAGNOSIS,
                RepairJobStateMachine::STATE_AWAITING_APPROVAL,
                RepairJobStateMachine::STATE_IN_REPAIR,
            ],
            RepairJob::query()->orderBy('id')->pluck('state')->all()
        );
    }

    public function test_every_seeded_state_is_reachable_through_the_state_machine(): void
    {
        $this->demoCustomers();

        $this->apply();

        foreach (RepairJob::query()->orderBy('id')->get() as $job) {
            $transitions = DB::table('recommerce_repair_state_transitions')
                ->where('repair_job_id', $job->id)
                ->orderBy('id')
                ->get();

            $this->assertSame(RepairJobStateMachine::STATE_RECEIVED, $transitions->first()->to_state);
            $this->assertNull($transitions->first()->from_state);
            $this->assertSame($job->state, $transitions->last()->to_state);

            $state = RepairJobStateMachine::STATE_RECEIVED;
            foreach ($transitions->slice(1) as $transition) {
                $this->assertSame($state, $transition->from_state);
                $this->assertContains(
                    $transition->to_state,
                    RepairJobStateMachine::allowedTransitions($state),
                    sprintf('Seeded transition %s -> %s is not legal.', $state, $transition->to_state)
                );
                $state = $transition->to_state;
            }

            $this->assertSame($job->state, $state);
            $this->assertSame($transitions->count(), (int) $job->lock_version);
        }
    }

    public function test_each_job_carries_the_configured_intake_checklist_and_a_lookup_token(): void
    {
        $this->demoCustomers();

        $this->apply();

        $checklist = (array) config('recommerce.repair_intake_checklist', []);
        $this->assertNotEmpty($checklist, 'The module intake checklist config must reach the fixture.');

        foreach (RepairJob::query()->orderBy('id')->get() as $job) {
            $items = DB::table('recommerce_repair_checklist_items')
                ->where('repair_job_id', $job->id)
                ->orderBy('id')
                ->get();

            $this->assertSame(array_column($checklist, 'key'), $items->pluck('check_key')->all());
            $this->assertSame(array_column($checklist, 'label'), $items->pluck('label')->all());
            foreach ($items as $item) {
                $this->assertContains($item->outcome, ['PASS', 'FAIL', 'NOT_APPLICABLE']);
            }

            $this->assertSame(1, DB::table('recommerce_repair_lookup_tokens')
                ->where('repair_job_id', $job->id)
                ->where('status', 'ACTIVE')
                ->count());
        }

        $this->assertGreaterThan(
            0,
            DB::table('recommerce_repair_checklist_items')->where('outcome', 'FAIL')->count(),
            'A demo queue where every check passes cannot exercise the failed-check styling.'
        );
    }

    public function test_reapplying_the_fixture_changes_nothing(): void
    {
        $this->demoCustomers();
        $this->apply();

        $jobs = RepairJob::query()->orderBy('id')->get(['id', 'job_code', 'state', 'lock_version'])->toArray();
        $deviceCount = Device::query()->count();

        $this->assertSame(0, $this->apply());
        $this->assertSame($jobs, RepairJob::query()->orderBy('id')->get(['id', 'job_code', 'state', 'lock_version'])->toArray());
        $this->assertSame($deviceCount, Device::query()->count());
        $this->assertSame(4, DB::table('recommerce_repair_lookup_tokens')->count());
    }

    public function test_a_missing_demo_customer_is_skipped_without_losing_the_other_jobs(): void
    {
        $this->demoCustomers(['CUS-DEMO-001', 'CUS-DEMO-002', 'CUS-DEMO-004']);

        $created = $this->apply();

        $this->assertSame(3, $created);
        $this->assertSame(3, RepairJob::query()->count());
        $this->assertSame(3, Device::query()->count());
    }

    public function test_a_soft_deleted_demo_customer_is_not_used(): void
    {
        $this->demoCustomers();
        DB::table('contacts')->where('contact_id', 'CUS-DEMO-003')->update(['deleted_at' => now()]);

        $this->assertSame(3, $this->apply());
        $this->assertSame(
            0,
            RepairJob::query()->where('contact_id', DB::table('contacts')->where('contact_id', 'CUS-DEMO-003')->value('id'))->count()
        );
    }

    private function apply(): int
    {
        return SaverposDemoRepairFixture::apply(self::BUSINESS_ID, self::LOCATION_ID, self::USER_ID);
    }

    /**
     * @param  array<int, string>  $references
     */
    private function demoCustomers(array $references = ['CUS-DEMO-001', 'CUS-DEMO-002', 'CUS-DEMO-003', 'CUS-DEMO-004']): void
    {
        foreach ($references as $reference) {
            DB::table('contacts')->insert([
                'business_id' => self::BUSINESS_ID,
                'name' => 'Demo customer '.$reference,
                'contact_id' => $reference,
                'type' => 'customer',
            ]);
        }
    }
}
