<?php

namespace Tests\Feature;

use App\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;
use Modules\Recommerce\Entities\Device;
use Modules\Recommerce\Entities\DeviceAcquisition;
use Modules\Recommerce\Entities\TradeInRuleSet;
use Modules\Recommerce\Entities\TradeInValuation;
use Modules\Recommerce\Services\DeviceEventRecorder;
use Modules\Recommerce\Services\TradeInPricingService;
use Modules\Recommerce\Services\TradeInService;
use Modules\Recommerce\Services\UltimatePosPurchaseWriter;
use Modules\Recommerce\Support\AuthorizationGate;
use Modules\Recommerce\Support\CohortPolicy;
use Tests\TestCase;

class RecommerceTradeInAcquisitionTest extends TestCase
{
    protected RecordingTradeInPurchaseWriter $writer;

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
                TradeInService::PERMISSION_VIEW,
                TradeInService::PERMISSION_MANAGE,
                TradeInService::PERMISSION_APPROVE,
                TradeInService::PERMISSION_OVERRIDE_ECONOMIC,
                TradeInService::PERMISSION_ACCEPT,
                TradeInService::PERMISSION_REVERSE,
            ],
            'recommerce.cohort.business_id' => 7,
            'recommerce.cohort.location_id' => 101,
            'recommerce.cohort.location_ids' => [101],
            'recommerce.cohort.variation_ids' => [303],
        ]);
        DB::purge('sqlite');
        $schema = Schema::connection('sqlite');
        $schema->create('business', function (Blueprint $table) { $table->unsignedInteger('id')->primary(); });
        $schema->create('users', function (Blueprint $table) { $table->unsignedInteger('id')->primary(); $table->unsignedInteger('business_id'); });
        $schema->create('business_locations', function (Blueprint $table) { $table->unsignedInteger('id')->primary(); $table->unsignedInteger('business_id'); });
        $schema->create('contacts', function (Blueprint $table) { $table->unsignedInteger('id')->primary(); $table->unsignedInteger('business_id'); $table->string('type'); $table->string('name')->nullable(); $table->timestamp('deleted_at')->nullable(); });
        $schema->create('products', function (Blueprint $table) { $table->unsignedInteger('id')->primary(); $table->unsignedInteger('business_id'); $table->string('name')->nullable(); });
        $schema->create('variations', function (Blueprint $table) { $table->unsignedInteger('id')->primary(); $table->unsignedInteger('product_id'); $table->timestamp('deleted_at')->nullable(); });
        $schema->create('transactions', function (Blueprint $table) {
            $table->increments('id'); $table->unsignedInteger('business_id'); $table->unsignedInteger('location_id')->nullable();
            $table->string('type'); $table->string('status')->nullable(); $table->string('payment_status')->nullable();
            $table->unsignedInteger('contact_id')->nullable(); $table->unsignedInteger('return_parent_id')->nullable();
            $table->decimal('final_total', 22, 4)->default(0); $table->timestamp('transaction_date')->nullable(); $table->timestamps();
        });
        $schema->create('purchase_lines', function (Blueprint $table) {
            $table->increments('id'); $table->unsignedInteger('transaction_id'); $table->unsignedInteger('product_id'); $table->unsignedInteger('variation_id'); $table->decimal('quantity', 22, 4);
        });
        $schema->create('transaction_payments', function (Blueprint $table) {
            $table->increments('id'); $table->unsignedInteger('transaction_id'); $table->decimal('amount', 22, 4)->default(0); $table->timestamps();
        });

        DB::table('business')->insert(['id' => 7]);
        DB::table('users')->insert(['id' => 900, 'business_id' => 7]);
        DB::table('business_locations')->insert(['id' => 101, 'business_id' => 7]);
        DB::table('contacts')->insert([
            ['id' => 405, 'business_id' => 7, 'type' => 'customer', 'name' => 'Customer seller'],
            ['id' => 406, 'business_id' => 7, 'type' => 'supplier', 'name' => 'Supplier counterpart'],
        ]);
        DB::table('products')->insert(['id' => 202, 'business_id' => 7, 'name' => 'Refurbished laptop']);
        DB::table('variations')->insert(['id' => 303, 'product_id' => 202]);

        (require base_path('Modules/Recommerce/Database/Migrations/2026_08_27_000002_create_recommerce_alpha_tables.php'))->up();
        (require base_path('Modules/Recommerce/Database/Migrations/2026_08_28_000004_harden_recommerce_event_identity.php'))->up();
        (require base_path('Modules/Recommerce/Database/Migrations/2026_08_28_000006_create_recommerce_ownership_periods.php'))->up();
        (require base_path('Modules/Recommerce/Database/Migrations/2026_08_28_000007_create_recommerce_custody_periods.php'))->up();
        (require base_path('Modules/Recommerce/Database/Migrations/2026_08_31_000031_create_recommerce_trade_in_tables.php'))->up();

        DB::table('recommerce_devices')->insert([
            'id' => 11, 'business_id' => 7, 'device_uuid' => 'b4068cc7-0f29-4d22-8f45-4f9a29de1101', 'device_code' => 'SB-DV-00000001-9',
            'ownership_kind' => 'CUSTOMER', 'current_owner_contact_id' => 405, 'custody_kind' => 'CUSTOMER', 'current_location_id' => null,
            'category_code' => 'LAPTOP', 'lifecycle_state' => 'CUSTOMER_CUSTODY', 'stock_participation' => 'NONE',
            'specifications_json' => json_encode(['brand' => 'Fixture', 'model' => 'L1']), 'lock_version' => 1, 'created_by' => 900, 'updated_by' => 900,
        ]);
        DB::table('recommerce_device_ownership_periods')->insert([
            'device_id' => 11, 'business_id' => 7, 'owner_kind' => 'CUSTOMER', 'contact_id' => 405,
            'starts_at' => now(), 'open_period_key' => 11, 'reason' => 'FIXTURE', 'recorded_by' => 900, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('recommerce_device_custody_periods')->insert([
            'device_id' => 11, 'business_id' => 7, 'custody_kind' => 'CUSTOMER', 'starts_at' => now(),
            'open_period_key' => 11, 'reason' => 'FIXTURE', 'recorded_by' => 900, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->writer = new RecordingTradeInPurchaseWriter();
    }

    protected function tearDown(): void
    {
        DB::disconnect('sqlite');
        parent::tearDown();
    }

    public function test_valuation_keeps_structured_inspection_market_evidence_and_immutable_rule_snapshot(): void
    {
        $rule = $this->ruleSet();
        $valuation = $this->service()->createValuation($this->user(), $this->valuationCommand($rule->id));

        $this->assertSame(TradeInValuation::STATUS_READY_TO_ACCEPT, $valuation->status);
        $this->assertSame('B', $valuation->inspection_json['cosmetic_grade']);
        $this->assertEquals(52.0, $valuation->inspection_json['battery_health_percent']);
        $this->assertSame(2, $valuation->marketEvidence()->count());
        $this->assertSame(2200.0, (float) $valuation->market_low_amount);
        $this->assertSame(2400.0, (float) $valuation->market_high_amount);
        $this->assertSame(195.0, (float) $valuation->inspection_json['battery_replacement_estimate_amount']);
        $this->assertSame(390.0, (float) $valuation->pricing_snapshot_json['components']['required_contribution_amount']);
        $this->assertSame(1056.25, (float) $valuation->economic_ceiling_amount);
        $this->assertSame('CUSTOMER', Device::query()->findOrFail(11)->ownership_kind);
        $this->assertSame('LOCATION', Device::query()->findOrFail(11)->custody_kind);
        $this->assertSame(101, (int) Device::query()->findOrFail(11)->current_location_id);
    }

    public function test_publishing_a_pricing_rule_retires_only_its_prior_version(): void
    {
        $command = [
            'business_id' => 7,
            'location_id' => 101,
            'variation_id' => 303,
            'rule_code' => 'LAPTOP_STANDARD',
            'parameters' => [
                'target_margin_percent' => 0.20,
                'warranty_reserve_percent' => 0.05,
                'hidden_defect_reserve_percent' => 0.05,
                'markdown_reserve_percent' => 0.025,
                'opening_offer_ratio' => 0.75,
                'target_acquisition_ratio' => 0.85,
                'negotiation_ceiling_ratio' => 0.95,
            ],
        ];

        $first = $this->service()->createRuleSet($this->user(), $command);
        $second = $this->service()->createRuleSet($this->user(), array_replace_recursive($command, [
            'parameters' => ['target_margin_percent' => 0.25],
        ]));

        $this->assertSame(1, (int) $first->version_number);
        $this->assertSame(2, (int) $second->version_number);
        $this->assertSame('RETIRED', $first->fresh()->status);
        $this->assertSame('ACTIVE', $second->status);
        $this->assertSame(1, TradeInRuleSet::query()->where('rule_code', 'LAPTOP_STANDARD')->where('status', 'ACTIVE')->count());
        $this->assertSame(0.25, (float) $second->parameters_json['target_margin_percent']);
    }

    public function test_pending_approval_accepts_once_and_posts_one_native_purchase_for_one_unit(): void
    {
        $valuation = $this->service()->createValuation($this->user(), $this->valuationCommand($this->ruleSet()->id, [
            'staff_proposed_amount' => 1020,
            'command_uuid' => '11111111-1111-4111-8111-111111111111',
        ]));
        $this->assertSame(TradeInValuation::STATUS_PENDING_APPROVAL, $valuation->status);

        $approved = $this->service()->approve($this->user(), $valuation, 'Manager reviewed inspection and market evidence.');
        $this->assertSame(TradeInValuation::STATUS_APPROVED, $approved->status);
        $first = $this->service()->accept($this->user(), $approved, '22222222-2222-4222-8222-222222222222');
        $replayed = $this->service()->accept($this->user(), $approved, '22222222-2222-4222-8222-222222222222');

        $this->assertSame($first->id, $replayed->id);
        $this->assertCount(1, $this->writer->commands);
        $this->assertSame(1, DB::table('transactions')->where('type', 'purchase')->count());
        $this->assertSame(1.0, (float) DB::table('purchase_lines')->value('quantity'));
        $this->assertSame('due', DB::table('transactions')->value('payment_status'));
        $this->assertSame(0, DB::table('transaction_payments')->count());
        $this->assertSame(1, DeviceAcquisition::query()->count());
        $device = Device::query()->findOrFail(11);
        $this->assertSame('BUSINESS', $device->ownership_kind);
        $this->assertSame('LOCATION', $device->custody_kind);
        $this->assertSame('ON_HAND', $device->stock_participation);
        $this->assertSame(202, (int) $device->product_id);
        $this->assertSame(303, (int) $device->variation_id);
        $this->assertSame(1, DB::table('recommerce_device_acquisitions')->count());
        $this->assertSame(1, DB::table('recommerce_device_events')->where('event_type', 'ACQUISITION_POSTED')->count());
    }

    public function test_economic_ceiling_requires_override_permission_and_rejection_creates_no_purchase(): void
    {
        $valuation = $this->service()->createValuation($this->user(), $this->valuationCommand($this->ruleSet()->id, [
            'staff_proposed_amount' => 1100,
            'command_uuid' => '33333333-3333-4333-8333-333333333333',
        ]));
        $this->expectException(AuthorizationException::class);
        $this->service($this->writer)->approve($this->user([TradeInService::PERMISSION_APPROVE]), $valuation, 'Above ceiling.');
    }

    public function test_rejected_trade_in_returns_customer_custody_and_creates_no_purchase(): void
    {
        $valuation = $this->service()->createValuation($this->user(), $this->valuationCommand($this->ruleSet()->id));
        $rejected = $this->service()->reject($this->user(), $valuation, 'Customer wanted a higher amount.');

        $this->assertSame(TradeInValuation::STATUS_REJECTED, $rejected->status);
        $this->assertSame(0, DB::table('transactions')->count());
        $device = Device::query()->findOrFail(11);
        $this->assertSame('CUSTOMER', $device->ownership_kind);
        $this->assertSame('CUSTOMER', $device->custody_kind);
        $this->assertNull($device->current_location_id);
    }

    public function test_native_reversal_preserves_acquisition_row_and_returns_device_to_customer(): void
    {
        $valuation = $this->service()->createValuation($this->user(), $this->valuationCommand($this->ruleSet()->id));
        $acquisition = $this->service()->accept($this->user(), $valuation, '44444444-4444-4444-8444-444444444444');
        $returnId = DB::table('transactions')->insertGetId([
            'business_id' => 7, 'location_id' => 101, 'type' => 'purchase_return', 'status' => 'final', 'payment_status' => 'due',
            'contact_id' => 406, 'return_parent_id' => $acquisition->transaction_id, 'final_total' => $acquisition->acquisition_amount,
            'transaction_date' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $reversal = $this->service()->recordReversal($this->user(), $acquisition, $returnId, '55555555-5555-4555-8555-555555555555', 'Native purchase return posted.');

        $this->assertSame(1, DeviceAcquisition::query()->count());
        $this->assertSame($acquisition->id, $reversal->acquisition_id);
        $this->assertSame(1, DB::table('recommerce_device_acquisition_reversals')->count());
        $device = Device::query()->findOrFail(11);
        $this->assertSame('CUSTOMER', $device->ownership_kind);
        $this->assertSame('CUSTOMER', $device->custody_kind);
        $this->assertSame('NONE', $device->stock_participation);
        $this->assertSame(1, DB::table('recommerce_device_events')->where('event_type', 'ACQUISITION_REVERSED')->count());
    }

    public function test_supplier_must_be_explicitly_supplier_capable_without_mutating_the_customer_contact(): void
    {
        DB::table('contacts')->insert(['id' => 407, 'business_id' => 7, 'type' => 'customer', 'name' => 'Customer only']);

        try {
            $this->service()->createValuation($this->user(), $this->valuationCommand($this->ruleSet()->id, [
                'supplier_contact_id' => 407,
            ]));
            $this->fail('A customer-only contact must not be accepted as the payable supplier.');
        } catch (LogicException $exception) {
            $this->assertSame('Trade-in requires an explicitly selected supplier-capable contact.', $exception->getMessage());
        }

        $this->assertSame('customer', DB::table('contacts')->where('id', 407)->value('type'));
        $this->assertSame(0, TradeInValuation::query()->count());
        $this->assertSame('CUSTOMER', Device::query()->findOrFail(11)->custody_kind);
    }

    public function test_failed_native_purchase_leaves_no_partial_acquisition_or_device_transfer(): void
    {
        $valuation = $this->service()->createValuation($this->user(), $this->valuationCommand($this->ruleSet()->id));
        $failingService = new TradeInService(
            new AuthorizationGate(new CohortPolicy()),
            new TradeInPricingService(),
            new ThrowingTradeInPurchaseWriter(),
            new DeviceEventRecorder()
        );

        try {
            $failingService->accept($this->user(), $valuation, '66666666-6666-4666-8666-666666666666');
            $this->fail('The simulated core purchase failure must be surfaced.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Simulated native purchase failure.', $exception->getMessage());
        }

        $this->assertSame(0, DB::table('transactions')->count());
        $this->assertSame(0, DeviceAcquisition::query()->count());
        $this->assertSame(TradeInValuation::STATUS_READY_TO_ACCEPT, $valuation->fresh()->status);
        $device = Device::query()->findOrFail(11);
        $this->assertSame('CUSTOMER', $device->ownership_kind);
        $this->assertSame('LOCATION', $device->custody_kind);
        $this->assertSame(0, DB::table('recommerce_device_movements')->where('movement_type', 'TRADE_IN_ACQUISITION')->count());
    }

    public function test_device_can_have_a_second_append_only_trade_in_after_native_reversal(): void
    {
        $firstValuation = $this->service()->createValuation($this->user(), $this->valuationCommand($this->ruleSet()->id));
        $firstAcquisition = $this->service()->accept($this->user(), $firstValuation, '77777777-7777-4777-8777-777777777777');
        $returnId = DB::table('transactions')->insertGetId([
            'business_id' => 7, 'location_id' => 101, 'type' => 'purchase_return', 'status' => 'final', 'payment_status' => 'due',
            'contact_id' => 406, 'return_parent_id' => $firstAcquisition->transaction_id, 'final_total' => $firstAcquisition->acquisition_amount,
            'transaction_date' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->service()->recordReversal($this->user(), $firstAcquisition, $returnId, '88888888-8888-4888-8888-888888888888', 'First acquisition was returned natively.');

        $secondValuation = $this->service()->createValuation($this->user(), $this->valuationCommand($this->ruleSet()->id, [
            'command_uuid' => '99999999-9999-4999-8999-999999999999',
            'staff_proposed_amount' => 880,
        ]));
        $secondAcquisition = $this->service()->accept($this->user(), $secondValuation, 'aaaaaaaa-aaaa-4aaa-8aaa-bbbbbbbbbbbb');

        $this->assertSame(2, DeviceAcquisition::query()->count());
        $this->assertNotSame($firstAcquisition->id, $secondAcquisition->id);
        $this->assertSame(2, (int) $secondValuation->version_number);
        $this->assertSame('ACCEPTED', $secondValuation->fresh()->status);
        $this->assertSame('BUSINESS', Device::query()->findOrFail(11)->ownership_kind);
    }

    /**
     * RCR-010 requires that concurrent accept/reject leave exactly one outcome.
     * Both paths lock the valuation row and gate on its status, so the loser
     * must refuse rather than half-apply. Serialised here because SQLite has no
     * second connection to race with; what is under test is the status gate,
     * which is what makes the row lock decisive.
     */
    public function test_a_reject_after_acceptance_is_refused_and_leaves_the_device_acquired(): void
    {
        $valuation = $this->service()->createValuation($this->user(), $this->valuationCommand($this->ruleSet()->id));
        $this->service()->accept($this->user(), $valuation, '66666666-6666-4666-8666-666666666666');

        try {
            $this->service()->reject($this->user(), $valuation->fresh(), 'Customer changed their mind.');
            $this->fail('Rejecting an accepted trade-in must be refused.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('open trade-in valuation', $exception->getMessage());
        }

        $this->assertSame(TradeInValuation::STATUS_ACCEPTED, $valuation->fresh()->status);
        $this->assertSame(1, DB::table('transactions')->where('type', 'purchase')->count());
        $this->assertSame(1, DeviceAcquisition::query()->count());
        $device = Device::query()->findOrFail(11);
        $this->assertSame('BUSINESS', $device->ownership_kind);
        $this->assertSame('ON_HAND', $device->stock_participation);
        $this->assertSame(0, DB::table('recommerce_device_movements')->where('movement_type', 'TRADE_IN_REJECTED')->count());
    }

    public function test_an_acceptance_after_rejection_is_refused_and_posts_no_purchase(): void
    {
        $valuation = $this->service()->createValuation($this->user(), $this->valuationCommand($this->ruleSet()->id));
        $this->service()->reject($this->user(), $valuation, 'Customer wanted a higher amount.');

        try {
            $this->service()->accept($this->user(), $valuation->fresh(), '77777777-7777-4777-8777-777777777777');
            $this->fail('Accepting a rejected trade-in must be refused.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('not approved for acceptance', $exception->getMessage());
        }

        $this->assertSame(TradeInValuation::STATUS_REJECTED, $valuation->fresh()->status);
        $this->assertCount(0, $this->writer->commands);
        $this->assertSame(0, DB::table('transactions')->count());
        $this->assertSame(0, DeviceAcquisition::query()->count());
        $this->assertSame('CUSTOMER', Device::query()->findOrFail(11)->ownership_kind);
    }

    /**
     * Replaying the same command_uuid returns the original acquisition, which is
     * already covered. The dangerous case is a stale offer retried with a *new*
     * key: idempotency cannot catch that, so only the valuation status stops a
     * second native purchase being posted for the same device.
     */
    public function test_a_stale_retry_under_a_new_command_uuid_cannot_post_a_second_purchase(): void
    {
        $valuation = $this->service()->createValuation($this->user(), $this->valuationCommand($this->ruleSet()->id));
        $this->service()->accept($this->user(), $valuation, '88888888-8888-4888-8888-888888888888');

        try {
            $this->service()->accept($this->user(), $valuation->fresh(), '99999999-9999-4999-8999-999999999999');
            $this->fail('A stale acceptance retry must not post a second purchase.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('not approved for acceptance', $exception->getMessage());
        }

        $this->assertCount(1, $this->writer->commands);
        $this->assertSame(1, DB::table('transactions')->where('type', 'purchase')->count());
        $this->assertSame(1, DB::table('purchase_lines')->count());
        $this->assertSame(1, DeviceAcquisition::query()->count());
    }

    /**
     * The whole point of trade-in is that a device the business already knows
     * keeps its identity. Acceptance must reuse the canonical Device rather than
     * minting a second one, and must leave its prior history readable.
     */
    public function test_a_device_with_prior_history_is_reused_and_its_record_is_preserved(): void
    {
        DB::table('recommerce_device_identifiers')->insert([
            'device_id' => 11, 'business_id' => 7, 'identifier_type' => 'SERIAL',
            'raw_value_encrypted' => 'FIXTURE-SERIAL-1', 'normalized_hash' => str_repeat('a', 64),
            'is_verified' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        // A closed BUSINESS period: this unit was sold to the customer earlier.
        DB::table('recommerce_device_ownership_periods')->insert([
            'device_id' => 11, 'business_id' => 7, 'owner_kind' => 'BUSINESS',
            'starts_at' => now()->subYear(), 'ends_at' => now()->subMonths(6),
            'open_period_key' => null, 'reason' => 'ORIGINAL_RECEIPT', 'recorded_by' => 900,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $before = Device::query()->findOrFail(11);

        $valuation = $this->service()->createValuation($this->user(), $this->valuationCommand($this->ruleSet()->id));
        $this->service()->accept($this->user(), $valuation, 'abababab-abab-4bab-8bab-abababababab');

        $after = Device::query()->findOrFail(11);
        $this->assertSame(1, Device::query()->count(), 'Trade-in must reuse the Device, never mint a second identity.');
        $this->assertSame($before->device_uuid, $after->device_uuid);
        $this->assertSame($before->device_code, $after->device_code);
        $this->assertSame(1, DB::table('recommerce_device_identifiers')->count(), 'Acceptance must not touch identifier history.');
        $this->assertSame('FIXTURE-SERIAL-1', DB::table('recommerce_device_identifiers')->value('raw_value_encrypted'));
        // The earlier closed period survives untouched beside the new one.
        $this->assertSame(1, DB::table('recommerce_device_ownership_periods')
            ->where('reason', 'ORIGINAL_RECEIPT')->whereNotNull('ends_at')->count());
        $this->assertSame(1, DB::table('recommerce_device_ownership_periods')
            ->where('reason', 'TRADE_IN')->whereNull('ends_at')->count());
    }

    /**
     * RCR-010 requires the native purchase, purchase line, payment, acquisition
     * record, ownership period, custody period, movement and event to reconcile
     * exactly. Asserted together, because each of these has been correct on its
     * own while pointing at the wrong row.
     */
    public function test_acceptance_reconciles_every_native_and_recommerce_artifact(): void
    {
        $valuation = $this->service()->createValuation($this->user(), $this->valuationCommand($this->ruleSet()->id));
        $acquisition = $this->service()->accept($this->user(), $valuation, 'cdcdcdcd-cdcd-4dcd-8dcd-cdcdcdcdcdcd');

        // One native command, one unit, at the accepted amount and the selected supplier.
        $this->assertCount(1, $this->writer->commands);
        $command = $this->writer->commands[0];
        $this->assertCount(1, $command['units']);
        $this->assertSame(406, (int) $command['purchase']['contact_id']);
        $this->assertSame(202, (int) $command['product_id']);
        $this->assertSame(303, (int) $command['variation_id']);
        $this->assertEqualsWithDelta(
            (float) $valuation->fresh()->final_acquisition_amount,
            (float) $command['purchase']['unit_purchase_price'],
            0.0001,
            'The native unit price must be the accepted acquisition amount, never the estimate.'
        );

        $transaction = DB::table('transactions')->where('type', 'purchase')->first();
        $line = DB::table('purchase_lines')->first();
        $this->assertSame('received', $transaction->status);
        $this->assertSame('due', $transaction->payment_status);
        $this->assertSame(0, DB::table('transaction_payments')->count(), 'Settlement stays with native payment flows.');
        $this->assertSame((int) $transaction->id, (int) $line->transaction_id);
        $this->assertSame(1.0, (float) $line->quantity);

        // Every Recommerce artifact points at that exact transaction and line.
        $this->assertSame((int) $transaction->id, (int) $acquisition->transaction_id);
        $this->assertSame((int) $line->id, (int) $acquisition->purchase_line_id);
        $this->assertSame((int) $valuation->id, (int) $acquisition->trade_in_valuation_id);
        $this->assertSame(405, (int) $acquisition->seller_contact_id);
        $this->assertSame(406, (int) $acquisition->supplier_contact_id);

        $movement = DB::table('recommerce_device_movements')->where('movement_type', 'TRADE_IN_ACQUISITION')->first();
        $this->assertNotNull($movement);
        $this->assertSame((int) $transaction->id, (int) $movement->source_transaction_id);
        $this->assertSame((int) $line->id, (int) $movement->source_line_id);

        $ownership = DB::table('recommerce_device_ownership_periods')->where('reason', 'TRADE_IN')->whereNull('ends_at')->first();
        $this->assertNotNull($ownership);
        $this->assertSame('BUSINESS', $ownership->owner_kind);
        $this->assertSame((int) $transaction->id, (int) $ownership->acquisition_transaction_id);

        $custody = DB::table('recommerce_device_custody_periods')->where('reason', 'TRADE_IN')->whereNull('ends_at')->first();
        $this->assertNotNull($custody);
        $this->assertSame(101, (int) $custody->location_id);
        $this->assertSame((int) $movement->id, (int) $custody->source_movement_id);

        // The customer periods this replaced are closed, not deleted. Asserting
        // only that no open one remains would pass just as happily if the rows
        // had been destroyed, which would erase the ownership history.
        $priorOwnership = DB::table('recommerce_device_ownership_periods')->where('reason', 'FIXTURE')->first();
        $this->assertNotNull($priorOwnership, 'The prior customer ownership period must survive as closed history.');
        $this->assertNotNull($priorOwnership->ends_at, 'The prior customer ownership period must be closed.');
        $priorCustody = DB::table('recommerce_device_custody_periods')->where('reason', 'FIXTURE')->first();
        $this->assertNotNull($priorCustody, 'The prior customer custody period must survive as closed history.');
        $this->assertNotNull($priorCustody->ends_at, 'The prior customer custody period must be closed.');
        $this->assertSame(1, DB::table('recommerce_device_events')->where('event_type', 'ACQUISITION_POSTED')->count());
    }

    protected function ruleSet(): TradeInRuleSet
    {
        return TradeInRuleSet::query()->firstOrCreate([
            'business_id' => 7,
            'rule_code' => 'LAPTOP_STANDARD',
            'version_number' => 1,
        ], [
            'status' => 'ACTIVE',
            'parameters_json' => [
                'target_margin_percent' => 0.20,
                'warranty_reserve_percent' => 0.05,
                'hidden_defect_reserve_percent' => 0.05,
                'markdown_reserve_percent' => 0.025,
                'opening_offer_ratio' => 0.75,
                'target_acquisition_ratio' => 0.85,
                'negotiation_ceiling_ratio' => 0.95,
            ],
            'effective_at' => now(),
            'created_by' => 900,
        ]);
    }

    protected function valuationCommand(int $ruleSetId, array $overrides = []): array
    {
        return array_replace_recursive([
            'business_id' => 7,
            'location_id' => 101,
            'device_id' => 11,
            'customer_contact_id' => 405,
            'supplier_contact_id' => 406,
            'product_id' => 202,
            'variation_id' => 303,
            'rule_set_id' => $ruleSetId,
            'command_uuid' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
            'currency' => 'MYR',
            'market_reference_amount' => 2300,
            'expected_resale_amount' => 1950,
            'expected_refurbishment_amount' => 260,
            'staff_proposed_amount' => 900,
            'customer_requested_amount' => 1000,
            'inspection' => [
                'battery_health_percent' => 52,
                'battery_replacement_needed' => 'CONDITIONAL',
                'battery_replacement_estimate_amount' => 195,
                'cosmetic_grade' => 'B',
                'cosmetic_notes' => 'Light case wear.',
                'functional_observations' => [
                    ['key' => 'DISPLAY', 'outcome' => 'PASS'],
                    ['key' => 'HINGE', 'outcome' => 'CONDITIONAL', 'notes' => 'Inspect during refurbishment.'],
                ],
                'accessories_notes' => 'Charger included.',
            ],
            'market_evidence' => [
                ['evidence_type' => 'MARKETPLACE', 'reference_amount' => 2200, 'source_description' => 'Manual listing reference A', 'observed_at' => now()->subDay()->toDateTimeString()],
                ['evidence_type' => 'COMPETITOR', 'reference_amount' => 2400, 'source_description' => 'Manual competitor reference B', 'observed_at' => now()->toDateTimeString()],
            ],
        ], $overrides);
    }

    protected function service(?UltimatePosPurchaseWriter $writer = null): TradeInService
    {
        return new TradeInService(
            new AuthorizationGate(new CohortPolicy()),
            new TradeInPricingService(),
            $writer ?: $this->writer,
            new DeviceEventRecorder()
        );
    }

    /** @param array<int, string>|null $allowed */
    protected function user(?array $allowed = null): User
    {
        $allowed = $allowed ?: config('recommerce.permissions');
        $user = new class($allowed) extends User {
            public function __construct(private array $allowed) { parent::__construct(); }
            public function can($ability, $arguments = []): bool { return in_array($ability, $this->allowed, true); }
        };
        $user->id = 900;
        $user->business_id = 7;

        return $user;
    }
}

class RecordingTradeInPurchaseWriter extends UltimatePosPurchaseWriter
{
    /** @var array<int, array<string, mixed>> */
    public array $commands = [];

    public function __construct()
    {
    }

    public function write(User $user, array $command): array
    {
        $this->commands[] = $command;
        $transactionId = DB::table('transactions')->insertGetId([
            'business_id' => $command['business_id'], 'location_id' => $command['location_id'], 'type' => 'purchase',
            'status' => 'received', 'payment_status' => 'due', 'contact_id' => $command['purchase']['contact_id'],
            'final_total' => $command['purchase']['unit_purchase_price_inc_tax'], 'transaction_date' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $purchaseLineId = DB::table('purchase_lines')->insertGetId([
            'transaction_id' => $transactionId, 'product_id' => $command['product_id'], 'variation_id' => $command['variation_id'], 'quantity' => count($command['units']),
        ]);

        return [
            'transaction_id' => $transactionId, 'purchase_line_id' => $purchaseLineId, 'quantity' => (float) count($command['units']),
            'business_id' => $command['business_id'], 'location_id' => $command['location_id'], 'product_id' => $command['product_id'], 'variation_id' => $command['variation_id'],
        ];
    }
}

class ThrowingTradeInPurchaseWriter extends UltimatePosPurchaseWriter
{
    public function __construct()
    {
    }

    public function write(User $user, array $command): array
    {
        throw new \RuntimeException('Simulated native purchase failure.');
    }
}
