<?php

namespace Tests\Feature;

use App\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;
use Modules\Recommerce\Entities\RepairJob;
use Modules\Recommerce\Entities\RepairQuote;
use Modules\Recommerce\Services\RepairJobTransitionService;
use Modules\Recommerce\Services\RepairQuoteService;
use Modules\Recommerce\Support\AuthorizationGate;
use Modules\Recommerce\Support\CohortPolicy;
use Tests\TestCase;

class RecommerceRepairQuoteTest extends TestCase
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
                'recommerce.repair.quote.manage',
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
        });
        $schema->create('variations', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->unsignedInteger('product_id');
            $table->timestamp('deleted_at')->nullable();
        });
        $schema->create('contacts', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->unsignedInteger('business_id');
            $table->string('name')->nullable();
            $table->timestamp('deleted_at')->nullable();
        });

        DB::table('business')->insert(['id' => 7]);
        DB::table('users')->insert(['id' => 900, 'business_id' => 7]);
        DB::table('business_locations')->insert(['id' => 101, 'business_id' => 7]);
        DB::table('contacts')->insert(['id' => 405, 'business_id' => 7, 'name' => 'Fixture customer']);
        DB::table('products')->insert(['id' => 202, 'business_id' => 7, 'name' => 'Refurbished laptop']);
        DB::table('variations')->insert(['id' => 303, 'product_id' => 202]);

        (require base_path('Modules/Recommerce/Database/Migrations/2026_08_27_000002_create_recommerce_alpha_tables.php'))->up();
        (require base_path('Modules/Recommerce/Database/Migrations/2026_08_28_000008_create_recommerce_repair_jobs.php'))->up();
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
            'job_code' => 'SB-RP-TESTQUOTE01',
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

    public function test_sent_quote_version_is_immutable_and_requires_revision_for_scope_change(): void
    {
        $service = $this->quoteService();
        $job = $this->job();

        $quote = $this->createSentQuote($service, $job);
        $this->assertSame(1, $quote->version_number);
        $this->assertSame('SENT', $quote->status);
        $this->assertSame('150.0000', (string) $quote->total_amount);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('immutable');

        $service->updateDraft($this->authorizedUser(), $quote, $this->linePayload(2, 500));
    }

    public function test_replay_of_the_same_command_uuid_returns_the_original_draft(): void
    {
        $service = $this->quoteService();
        $job = $this->job();

        $first = $service->createDraft(
            $this->authorizedUser(),
            $job,
            '22222222-2222-4222-8222-222222222221',
            $this->linePayload(1, 150)
        );
        $replayed = $service->createDraft(
            $this->authorizedUser(),
            $job,
            '22222222-2222-4222-8222-222222222221',
            $this->linePayload(9, 900)
        );

        $this->assertSame($first->getKey(), $replayed->getKey());
        $this->assertSame(1, RepairQuote::query()->where('repair_job_id', $job->id)->count());
        $this->assertSame(1, $replayed->lines()->count());
    }

    public function test_editing_a_draft_replaces_each_line_exactly_once(): void
    {
        $service = $this->quoteService();
        $draft = $service->createDraft(
            $this->authorizedUser(),
            $this->job(),
            '22222222-2222-4222-8222-222222222223',
            $this->linePayload(1, 150)
        );

        $updated = $service->updateDraft(
            $this->authorizedUser(),
            $draft,
            array_merge($this->linePayload(2, 100), [[
                'line_type' => 'PART',
                'description' => 'Replacement battery',
                'quantity' => 1,
                'unit_amount' => 75,
                'tax_amount' => 0,
            ]])
        );

        $this->assertCount(2, $updated->lines);
        $this->assertSame('275.0000', (string) $updated->total_amount);
        $this->assertSame(2, RepairQuote::query()->findOrFail($draft->id)->lines()->count());
    }

    public function test_second_conflicting_decision_is_rejected_after_the_first_wins(): void
    {
        $service = $this->quoteService();
        $job = $this->job();
        $quote = $this->createSentQuote($service, $job);

        $approved = $service->decide($this->authorizedUser(), $quote, 'APPROVED', ['approval_method' => 'PHONE', 'decision_evidence' => 'VERBAL-CONFIRMED']);
        $this->assertSame('APPROVED', $approved->status);
        $this->assertSame('VERBAL-CONFIRMED', $approved->decision_evidence_json['decision_evidence']);
        $this->assertNotNull($approved->decided_at);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('decided');

        $service->decide($this->authorizedUser(), $quote->fresh(), 'DECLINED', [], 'Attempt to flip the decision.');
    }

    public function test_customer_approval_requires_an_explicit_method(): void
    {
        $service = $this->quoteService();
        $quote = $this->createSentQuote($service, $this->job(), '22222222-2222-4222-8222-222222222224');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('explicit approval method');

        $service->decide($this->authorizedUser(), $quote, 'APPROVED', []);
    }

    public function test_declined_and_expired_quotes_cannot_be_approved(): void
    {
        $service = $this->quoteService();
        $job = $this->job();

        $declined = $this->createSentQuote($service, $job, '33333333-3333-4333-8333-333333333331');
        $service->decide($this->authorizedUser(), $declined, 'DECLINED', [], 'Customer declined.');

        $expired = $this->createSentQuote($service, $job, '33333333-3333-4333-8333-333333333332');
        RepairQuote::query()->whereKey($expired->getKey())->update([
            'expires_at' => now()->subDay(),
        ]);

        try {
            $service->decide($this->authorizedUser(), $expired->fresh(), 'APPROVED', []);
            $this->fail('Expected an expired sent quote to reject approval.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('unexpired', $exception->getMessage());
        }

        $this->assertSame('DECLINED', $declined->fresh()->status);
        $this->assertSame('EXPIRED', $expired->fresh()->status);
    }

    public function test_scope_increase_blocks_work_until_the_revised_quote_is_approved(): void
    {
        $service = $this->quoteService();
        $job = $this->job();
        $quote = $this->createSentQuote($service, $job);
        $approved = $service->decide($this->authorizedUser(), $quote, 'APPROVED', ['approval_method' => 'PHONE']);

        // Bring the job to diagnosis, then attempt to start repair work with the
        // still-valid approval of version 1.
        $job = app(RepairJobTransitionService::class)->transition(
            $job,
            'DIAGNOSIS',
            ['diagnostic_started' => true],
            1,
            900
        );

        $revised = $service->createDraft(
            $this->authorizedUser(),
            $job->fresh(),
            '44444444-4444-4444-8444-444444444441',
            array_merge($this->linePayload(1, 150), [['line_type' => 'PART', 'description' => 'Additional screen part', 'quantity' => 1, 'unit_amount' => 300]])
        );
        $sent = $service->send($this->authorizedUser(), $revised, 'MANUAL');

        try {
            app(RepairJobTransitionService::class)->transition(
                $job->fresh(),
                'IN_REPAIR',
                ['work_submitted' => true, 'approved_quote_id' => $approved->id],
                2,
                900
            );
            $this->fail('Expected a scope increase to block repair work until reapproval.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('revised quote must be approved', $exception->getMessage());
        }

        $service->decide($this->authorizedUser(), $sent->fresh(), 'APPROVED', ['approval_method' => 'PHONE', 'decision_evidence' => 'REAPPROVED']);
        $currentJob = $job->fresh();
        $updated = app(RepairJobTransitionService::class)->transition(
            $currentJob = $job->fresh(),
            'IN_REPAIR',
            ['work_submitted' => true, 'approved_quote_id' => $sent->id],
            (int) $currentJob->lock_version,
            900
        );
        $this->assertSame('IN_REPAIR', $updated->state);
        $this->assertSame('SUPERSEDED', $approved->fresh()->status);
    }

    public function test_missing_quote_permission_is_denied(): void
    {
        config(['recommerce.permissions' => ['recommerce.repair.view']]);
        $service = $this->quoteService();

        $this->expectException(AuthorizationException::class);
        $service->createDraft(
            $this->authorizedUser(),
            $this->job(),
            '55555555-5555-4555-8555-555555555551',
            $this->linePayload(1, 100)
        );
    }

    protected function quoteService(): RepairQuoteService
    {
        return new RepairQuoteService(new AuthorizationGate(new CohortPolicy()));
    }

    protected function job(): RepairJob
    {
        $job = RepairJob::query()->where('job_code', 'SB-RP-TESTQUOTE01')->firstOrFail();
        $this->assertNotNull($job->device);

        return $job;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function linePayload(int $quantity, float $unitAmount): array
    {
        return [[
            'line_type' => 'SERVICE',
            'description' => 'Fixture repair labour',
            'quantity' => $quantity,
            'unit_amount' => $unitAmount,
            'tax_amount' => 0,
        ]];
    }

    protected function createSentQuote(RepairQuoteService $service, RepairJob $job, string $commandUuid = '22222222-2222-4222-8222-222222222220'): RepairQuote
    {
        $draft = $service->createDraft($this->authorizedUser(), $job, $commandUuid, $this->linePayload(1, 150));

        return $service->send($this->authorizedUser(), $draft, 'MANUAL');
    }

    protected function authorizedUser(): User
    {
        $user = new class extends User
        {
            public function can($ability, $arguments = []): bool
            {
                return in_array($ability, [
                    'recommerce.repair.view',
                    'recommerce.repair.transition',
                    'recommerce.repair.quote.manage',
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
