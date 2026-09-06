<?php

namespace Tests\Feature;

use App\User;
use App\Transaction;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use LogicException;
use Modules\Recommerce\Entities\Device;
use Modules\Recommerce\Entities\DeviceAcquisition;
use Modules\Recommerce\Entities\TradeInRuleSet;
use Modules\Recommerce\Entities\TradeInQuickQuote;
use Modules\Recommerce\Entities\TradeInIntake;
use Modules\Recommerce\Entities\TradeInApprovedOffer;
use Modules\Recommerce\Entities\TradeInCustomerDecision;
use Modules\Recommerce\Entities\TradeInOutboxMessage;
use Modules\Recommerce\Entities\TradeInValuation;
use Modules\Recommerce\Http\Middleware\TradeInAcquisitionCommandToken;
use Modules\Recommerce\Services\DeviceEventRecorder;
use Modules\Recommerce\Services\TradeInPricingService;
use Modules\Recommerce\Services\TradeInQuickQuoteService;
use Modules\Recommerce\Services\TradeInRuleResolver;
use Modules\Recommerce\Services\TradeInNegotiationService;
use Modules\Recommerce\Services\TradeInAuthorityService;
use Modules\Recommerce\Services\TradeInAcquisitionCommandAccess;
use Modules\Recommerce\Services\TradeInService;
use Modules\Recommerce\Services\TradeInWebsiteCaseService;
use Modules\Recommerce\Services\TradeInOutboxDispatcher;
use Modules\Recommerce\Services\TradeInOutboxService;
use Modules\Recommerce\Services\SaverValueService;
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
        $schema->create('users', function (Blueprint $table) { $table->unsignedInteger('id')->primary(); $table->unsignedInteger('business_id'); $table->timestamp('deleted_at')->nullable(); });
        $schema->create('business_locations', function (Blueprint $table) { $table->unsignedInteger('id')->primary(); $table->unsignedInteger('business_id'); });
        $schema->create('contacts', function (Blueprint $table) { $table->unsignedInteger('id')->primary(); $table->unsignedInteger('business_id'); $table->string('type'); $table->string('name')->nullable(); $table->string('mobile')->nullable(); $table->timestamp('deleted_at')->nullable(); });
        $schema->create('roles', function (Blueprint $table) { $table->increments('id'); $table->string('name'); $table->string('guard_name')->default('web'); $table->unsignedInteger('business_id')->nullable(); });
        $schema->create('model_has_roles', function (Blueprint $table) { $table->unsignedInteger('role_id'); $table->string('model_type'); $table->unsignedInteger('model_id'); });
        $schema->create('products', function (Blueprint $table) { $table->unsignedInteger('id')->primary(); $table->unsignedInteger('business_id'); $table->string('name')->nullable(); });
        $schema->create('variations', function (Blueprint $table) { $table->unsignedInteger('id')->primary(); $table->unsignedInteger('product_id'); $table->decimal('sell_price_inc_tax', 22, 4)->default(0); $table->timestamp('deleted_at')->nullable(); });
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
        DB::table('variations')->insert(['id' => 303, 'product_id' => 202, 'sell_price_inc_tax' => 1950]);

        (require base_path('Modules/Recommerce/Database/Migrations/2026_08_27_000002_create_recommerce_alpha_tables.php'))->up();
        (require base_path('Modules/Recommerce/Database/Migrations/2026_08_28_000004_harden_recommerce_event_identity.php'))->up();
        (require base_path('Modules/Recommerce/Database/Migrations/2026_08_28_000006_create_recommerce_ownership_periods.php'))->up();
        (require base_path('Modules/Recommerce/Database/Migrations/2026_08_28_000007_create_recommerce_custody_periods.php'))->up();
        (require base_path('Modules/Recommerce/Database/Migrations/2026_08_31_000031_create_recommerce_trade_in_tables.php'))->up();
        (require base_path('Modules/Recommerce/Database/Migrations/2026_08_31_000033_extend_trade_in_for_branch_v2.php'))->up();
        (require base_path('Modules/Recommerce/Database/Migrations/2026_09_04_000001_create_recommerce_trade_in_quick_quotes.php'))->up();
        (require base_path('Modules/Recommerce/Database/Migrations/2026_09_06_000001_create_recommerce_trade_in_website_intakes.php'))->up();
        (require base_path('Modules/Recommerce/Database/Migrations/2026_09_06_000002_create_recommerce_trade_in_outbox_messages.php'))->up();

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
        $this->assertSame(429.0, (float) $valuation->pricing_snapshot_json['components']['required_contribution_amount']);
        $this->assertSame(960.0, (float) $valuation->economic_ceiling_amount);
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
        $this->assertSame('PENDING_QC', $device->lifecycle_state);
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

    public function test_v2_persists_declaration_laptop_inspection_and_negotiation_history(): void
    {
        $valuation = $this->service()->createValuation($this->user(), $this->valuationCommand($this->ruleSet()->id, [
            'seller_declaration_text' => 'Seller owns the laptop and authorises evaluation.',
            'seller_declaration_version' => 'V2',
            'seller_declaration_accepted' => true,
            'seller_identity_reference' => 'ID-REFERENCE-ONLY',
            'laptop_inspection' => [
                'brand' => 'Fixture', 'model' => 'L1', 'cpu' => 'Intel i5', 'ram' => '16 GB', 'storage' => '512 GB SSD',
                'cosmetic_grade' => 'B', 'functional_checks' => ['DISPLAY' => 'PASS', 'USB_PORTS' => 'PASS'],
                'battery_cycle_count' => 240, 'risk_flags' => ['MDM_MANAGED'],
            ],
        ]));

        $this->assertSame('V2', $valuation->seller_declaration_version);
        $this->assertNotNull($valuation->seller_declaration_accepted_at);
        $this->assertSame('Fixture', $valuation->laptopInspection->brand);
        $this->assertSame('PASS', $valuation->laptopInspection->functional_checks_json['USB_PORTS']);
        $this->assertSame(2, $valuation->negotiationEvents()->count());
    }

    public function test_v2_refuses_a_missing_seller_declaration_acknowledgement(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Seller declaration acknowledgement is required');
        $this->service()->createValuation($this->user(), $this->valuationCommand($this->ruleSet()->id, [
            'seller_declaration_text' => 'Seller owns the laptop.', 'seller_declaration_accepted' => false,
        ]));
    }

    public function test_quick_quote_uses_pricing_policy_without_creating_a_device_or_purchase(): void
    {
        $this->ruleSet();
        $service = new TradeInQuickQuoteService(
            new AuthorizationGate(new CohortPolicy()),
            new TradeInRuleResolver(),
            new TradeInPricingService()
        );
        $quote = $service->create($this->user(), [
            'location_id' => 101,
            'variation_id' => 303,
            'command_uuid' => '12121212-1212-4212-8212-121212121212',
            'customer_contact_id' => 405,
            'acquisition_type' => 'SELL_TO_SAVERBRO',
            'brand' => 'Fixture',
            'model' => 'QuoteBook',
            'cpu' => 'Intel i5',
            'ram' => '16 GB',
            'storage' => '512 GB SSD',
            'cosmetic_grade' => 'B',
            'battery_health_percent' => 82,
            'customer_expected_amount' => 1200,
            'expected_resale_amount' => 1950,
        ]);

        $this->assertSame(TradeInQuickQuote::STATUS_CONSIDERING, $quote->status);
        $this->assertSame('QuoteBook', $quote->specifications_json['model']);
        $this->assertSame(1, TradeInQuickQuote::query()->count());
        $this->assertSame(1, Device::query()->count(), 'Quick Quote must not create a Device.');
        $this->assertSame(0, DB::table('transactions')->count(), 'Quick Quote must not post a purchase.');
        $this->assertGreaterThan((float) $quote->estimated_low_amount, (float) $quote->estimated_high_amount);
        $this->assertTrue($quote->expires_at->isFuture());

        $declined = $service->decline($this->user(), $quote, 'OFFER_TOO_LOW', 'Customer wants RM 1,200.');
        $this->assertSame(TradeInQuickQuote::STATUS_CUSTOMER_DECLINED, $declined->status);
        $this->assertSame('OFFER_TOO_LOW', $declined->lost_reason_code);
    }

    public function test_unlisted_device_can_receive_a_non_posting_quick_quote_before_catalogue_confirmation(): void
    {
        $this->ruleSet();
        $service = new TradeInQuickQuoteService(
            new AuthorizationGate(new CohortPolicy()),
            new TradeInRuleResolver(),
            new TradeInPricingService()
        );

        $quote = $service->create($this->user(), [
            'location_id' => 101,
            'command_uuid' => '18181818-1818-4818-8818-181818181818',
            'brand' => 'Fictional',
            'model' => 'Unlisted New Device',
            'cosmetic_grade' => 'A',
            'expected_resale_amount' => 3200,
        ]);

        $this->assertNull($quote->product_id);
        $this->assertNull($quote->variation_id);
        $this->assertSame(0, DB::table('transactions')->count(), 'An unlisted Quick Quote must not post a purchase.');
        $this->assertSame(1, Device::query()->count(), 'An unlisted Quick Quote must not create a Device.');

        $valuation = $this->service()->createValuation($this->user(), $this->valuationCommand($this->ruleSet()->id));
        $service->continueToValuation($quote, $valuation);
        $this->assertSame(TradeInQuickQuote::STATUS_CONTINUED, $quote->fresh()->status);
    }

    public function test_staff_offer_updates_active_amount_and_approval_state_but_customer_counter_does_not(): void
    {
        $valuation = $this->service()->createValuation($this->user(), $this->valuationCommand($this->ruleSet()->id));
        $service = new TradeInNegotiationService(
            new AuthorizationGate(new CohortPolicy()),
            new TradeInAuthorityService(new AuthorizationGate(new CohortPolicy()))
        );
        $service->record($this->user(), $valuation, 'CUSTOMER_COUNTER', 1100, 'Customer countered.');
        $this->assertSame(900.0, (float) $valuation->fresh()->staff_proposed_amount);

        $service->record($this->user(), $valuation->fresh(), 'STAFF_OFFER', 1020, 'Requested manager approval.');
        $updated = $valuation->fresh();
        $this->assertSame(1020.0, (float) $updated->staff_proposed_amount);
        $this->assertSame(1020.0, (float) $updated->final_acquisition_amount);
        $this->assertSame(TradeInValuation::STATUS_PENDING_APPROVAL, $updated->status);
        $this->assertSame(4, $updated->negotiationEvents()->count());

        $returned = $this->service()->returnForRevision($this->user(), $updated, 'Reduce the offer or add stronger evidence.');
        $this->assertSame(TradeInValuation::STATUS_READY_TO_ACCEPT, $returned->status);
        $this->assertTrue((bool) $returned->approval_required);
        $this->assertSame('MANAGER_REVISION_REQUESTED', $returned->negotiationEvents()->get()->last()->event_type);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('requires recorded approval');
        $this->service()->accept($this->user(), $returned, '13131313-1313-4313-8313-131313131313');
    }

    public function test_quick_quote_continuation_links_the_formal_valuation_once(): void
    {
        $this->ruleSet();
        $quoteService = new TradeInQuickQuoteService(
            new AuthorizationGate(new CohortPolicy()),
            new TradeInRuleResolver(),
            new TradeInPricingService()
        );
        $quote = $quoteService->create($this->user(), [
            'location_id' => 101,
            'variation_id' => 303,
            'command_uuid' => '14141414-1414-4414-8414-141414141414',
            'customer_contact_id' => 405,
            'brand' => 'Fixture',
            'model' => 'QuoteBook',
            'cosmetic_grade' => 'B',
            'expected_resale_amount' => 1950,
        ]);
        $valuation = $this->service()->createValuation(
            $this->user(),
            $this->valuationCommand(TradeInRuleSet::query()->value('id'))
        );

        $quoteService->continueToValuation($quote, $valuation);
        $quoteService->continueToValuation($quote->fresh(), $valuation);

        $continued = $quote->fresh();
        $this->assertSame(TradeInQuickQuote::STATUS_CONTINUED, $continued->status);
        $this->assertSame($valuation->id, $continued->continued_to_valuation_id);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('still under consideration');
        $quoteService->decline($this->user(), $continued, 'OFFER_TOO_LOW', 'Too low after formal valuation.');
    }

    public function test_expired_quick_quote_requires_a_linked_revaluation_version(): void
    {
        $this->ruleSet();
        $quoteService = new TradeInQuickQuoteService(
            new AuthorizationGate(new CohortPolicy()),
            new TradeInRuleResolver(),
            new TradeInPricingService()
        );
        $base = [
            'location_id' => 101,
            'variation_id' => 303,
            'customer_contact_id' => 405,
            'brand' => 'Fixture',
            'model' => 'Expiring QuoteBook',
            'cosmetic_grade' => 'B',
            'expected_resale_amount' => 1950,
        ];
        $expired = $quoteService->create($this->user(), $base + [
            'command_uuid' => '15151515-1515-4515-8515-151515151515',
        ]);
        $expired->update(['expires_at' => now()->subMinute()]);
        $valuation = $this->service()->createValuation(
            $this->user(),
            $this->valuationCommand(TradeInRuleSet::query()->value('id'))
        );

        try {
            $quoteService->continueToValuation($expired->fresh(), $valuation);
            $this->fail('An expired quote must not continue to a formal valuation.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('expired', $exception->getMessage());
        }

        $replacement = $quoteService->create($this->user(), $base + [
            'command_uuid' => '16161616-1616-4616-8616-161616161616',
            'supersedes_quote_id' => $expired->id,
        ]);
        $this->assertSame($expired->id, (int) $replacement->supersedes_quote_id);
        $this->assertSame(TradeInQuickQuote::STATUS_CONSIDERING, $replacement->status);
        $this->assertSame(2, TradeInQuickQuote::query()->count());
        $this->assertNotSame($expired->quote_uuid, $replacement->quote_uuid);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Only an expired quote');
        $quoteService->create($this->user(), $base + [
            'command_uuid' => '17171717-1717-4717-8717-171717171717',
            'supersedes_quote_id' => $replacement->id,
        ]);
    }

    public function test_rejection_preserves_customer_outcome_and_competitor_context(): void
    {
        $valuation = $this->service()->createValuation($this->user(), $this->valuationCommand($this->ruleSet()->id));
        $rejected = $this->service()->reject($this->user(), $valuation, 'Competitor offered more.', [
            'reason_code' => 'COMPETITOR_OFFERED_MORE',
            'competitor_name' => 'Fictional Devices',
            'competitor_offer_amount' => 980,
        ]);

        $this->assertSame('COMPETITOR_OFFERED_MORE', $rejected->rejection_reason_code);
        $this->assertSame('Fictional Devices', $rejected->competitor_name);
        $this->assertSame(980.0, (float) $rejected->competitor_offer_amount);
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

    public function test_website_intake_offer_decision_mapping_and_replay_are_pos_authoritative(): void
    {
        $this->app->detectEnvironment(fn (): string => 'staging');
        config(['recommerce.tradein_acquisition_command' => [
            'enabled' => true, 'bearer_token' => str_repeat('w', 48), 'contract_version' => '1.0',
            'actor_user_id' => 900, 'business_id' => 7, 'location_ids' => [101], 'variation_ids' => [303],
        ]]);
        $access = app(TradeInAcquisitionCommandAccess::class);
        $middleware = app(TradeInAcquisitionCommandToken::class);
        $next = fn (): string => 'allowed';
        $this->assertSame(401, $middleware->handle(Request::create('/api/trade-in/v2/intakes', 'POST'), $next)->getStatusCode());
        $this->assertSame('allowed', $middleware->handle(Request::create('/api/trade-in/v2/intakes', 'POST', [], [], [], ['HTTP_AUTHORIZATION' => 'Bearer '.str_repeat('w', 48)]), $next));

        $native = $this->service();
        $cases = new TradeInWebsiteCaseService(new AuthorizationGate(new CohortPolicy()), $native);
        $submission = [
            'source_system' => 'SAVERBRO_WEBSITE', 'external_case_reference' => 'SB-TI-20260906-00001',
            'submission_id' => 'website-SB-TI-20260906-00001', 'submission_version' => 1,
            'category' => 'LAPTOP', 'brand' => 'Fixture', 'model' => 'Fixture L1',
            'specifications' => ['cpu' => 'i5'], 'declared_condition' => ['screen' => 'minor'],
            'indicative_snapshot' => ['estimate_min_minor' => 80000, 'estimate_max_minor' => 100000, 'pricing_policy_version' => 'SAVER-VALUE-POLICY-1.0'],
            'evidence_references' => [['evidence_id' => '11111111-1111-4111-8111-111111111111', 'evidence_type' => 'FRONT', 'source' => 'CUSTOMER', 'mime_type' => 'image/png']],
            'customer' => ['name' => 'Synthetic Customer', 'email' => 'customer@example.test', 'phone' => '0123456789'],
            'preferred_branch' => 'Fixture Branch', 'submitted_at' => now()->toDateTimeString(),
        ];
        $first = $cases->receive($submission, $access);
        $replay = $cases->receive($submission, $access);
        $this->assertFalse($first['replayed']);
        $this->assertTrue($replay['replayed']);
        $this->assertSame($first['intake']->id, $replay['intake']->id);
        $this->assertSame(1, TradeInIntake::query()->count());
        $this->assertSame(0, DB::table('transactions')->count());
        $this->assertSame(1, Device::query()->count(), 'Intake must not create a Device.');
        try { $cases->receive(array_replace($submission, ['model' => 'Tampered']), $access); $this->fail('Changed intake replay was accepted.'); }
        catch (LogicException $error) { $this->assertStringContainsString('different submission', $error->getMessage()); }

        $valuation = $native->createValuation($this->user(), $this->valuationCommand($this->ruleSet()->id));
        $cases->linkValuation($this->user(), $first['intake'], $valuation);
        try { $native->accept($this->user(), $valuation, 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee'); $this->fail('Website-origin valuation bypassed customer decision.'); }
        catch (LogicException $error) { $this->assertStringContainsString('exact POS-approved customer decision', $error->getMessage()); }
        $offer = $cases->publish($this->user(), $first['intake']->fresh());
        $this->assertSame('PUBLISHED', $offer->status);
        $this->assertSame(1, $offer->offer_version);
        $sameOffer = $cases->publish($this->user(), $first['intake']->fresh());
        $this->assertSame($offer->id, $sameOffer->id, 'Repeating publication for the same valuation must be idempotent.');
        $this->assertSame(1, TradeInApprovedOffer::query()->count());
        $mock = \Mockery::mock(TradeInService::class);
        $mock->shouldReceive('accept')->once()->andReturnUsing(
            fn (User $ignored, TradeInValuation $requested, string $key) => $native->accept($this->user(), $requested, $key)
        );
        $cases = new TradeInWebsiteCaseService(new AuthorizationGate(new CohortPolicy()), $mock);
        $base = [
            'offer_id' => $offer->offer_uuid, 'offer_version' => 1, 'decision' => 'ACCEPTED',
            'idempotency_key' => 'edededed-eded-4ded-8ded-edededededed',
            'native_mapping' => ['location_id' => 101, 'product_id' => 202, 'variation_id' => 303, 'device_id' => 11],
        ];
        $before = ['transactions' => DB::table('transactions')->count(), 'devices' => Device::query()->count(), 'acquisitions' => DeviceAcquisition::query()->count(), 'movements' => DB::table('recommerce_device_movements')->count()];
        foreach ([
            ['offer_id' => 'ffffffff-ffff-4fff-8fff-ffffffffffff', 'offer_version' => 1],
            ['offer_id' => $offer->offer_uuid, 'offer_version' => 2],
        ] as $wrongOffer) {
            $invalid = array_replace($base, $wrongOffer, ['idempotency_key' => (string) \Illuminate\Support\Str::uuid()]);
            try { $cases->decide($first['intake']->fresh(), $invalid, $access); $this->fail('A wrong or stale offer was accepted.'); }
            catch (LogicException $error) { $this->assertStringContainsString('stale', $error->getMessage()); }
        }
        $this->assertSame(0, TradeInCustomerDecision::query()->count());
        $logger = Log::getFacadeRoot(); Log::spy();
        foreach (['location_id' => 102, 'product_id' => 203, 'variation_id' => 304, 'device_id' => 12] as $field => $value) {
            $tampered = $base; $tampered['idempotency_key'] = (string) \Illuminate\Support\Str::uuid(); $tampered['native_mapping'][$field] = $value;
            try { $cases->decide($first['intake']->fresh(), $tampered, $access); $this->fail($field.' mismatch was accepted.'); }
            catch (LogicException $error) { $this->assertSame('native_mapping_mismatch', $error->getMessage()); }
            $this->assertSame($before['transactions'], DB::table('transactions')->count());
            $this->assertSame($before['devices'], Device::query()->count());
            $this->assertSame($before['acquisitions'], DeviceAcquisition::query()->count());
            $this->assertSame($before['movements'], DB::table('recommerce_device_movements')->count());
        }
        $multiple = $base;
        $multiple['idempotency_key'] = (string) \Illuminate\Support\Str::uuid();
        $multiple['native_mapping']['location_id'] = 102;
        $multiple['native_mapping']['product_id'] = 203;
        try { $cases->decide($first['intake']->fresh(), $multiple, $access); $this->fail('Multiple mismatches were accepted.'); }
        catch (LogicException $error) { $this->assertSame('native_mapping_mismatch', $error->getMessage()); }
        $missing = $base;
        $missing['idempotency_key'] = (string) \Illuminate\Support\Str::uuid();
        unset($missing['native_mapping']['device_id']);
        try { $cases->decide($first['intake']->fresh(), $missing, $access); $this->fail('A missing required mapping was accepted.'); }
        catch (LogicException $error) { $this->assertSame('native_mapping_mismatch', $error->getMessage()); }
        $this->assertSame($before['transactions'], DB::table('transactions')->count());
        $this->assertSame($before['devices'], Device::query()->count());
        $this->assertSame($before['acquisitions'], DeviceAcquisition::query()->count());
        $this->assertSame($before['movements'], DB::table('recommerce_device_movements')->count());
        Log::shouldHaveReceived('warning')->times(6); Log::swap($logger);
        $this->assertSame(6, DB::table('recommerce_trade_in_negotiation_events')->where('event_type', 'NATIVE_MAPPING_REJECTED')->count());
        $accepted = $cases->decide($first['intake']->fresh(), $base, $access);
        $again = $cases->decide($first['intake']->fresh(), $base, $access);
        $this->assertFalse($accepted['replayed']); $this->assertTrue($again['replayed']);
        $this->assertSame($accepted['decision']->id, $again['decision']->id);
        $this->assertSame(1, TradeInCustomerDecision::query()->count());
        $this->assertSame(1, DeviceAcquisition::query()->count());
        $this->assertSame(1, DB::table('transactions')->where('type', 'purchase')->count());
        $this->assertSame(1, Device::query()->count());
        $this->assertCount(1, $this->writer->commands);
        $projection = $cases->projection($first['intake']->fresh());
        $this->assertSame('ACQUIRED', $projection['status']);
        $this->assertSame(1, $projection['acquisition']['purchase_id']);
        $this->assertSame('PENDING', $projection['settlement']['status']);
        $this->assertSame(90000, $projection['settlement']['amount_minor']);
        $this->assertSame(4, TradeInOutboxMessage::query()->count(), 'Each authoritative customer-visible version must have one outbox event.');
        $this->assertSame(
            ['INTAKE_ACKNOWLEDGED', 'VALUATION_LINKED', 'APPROVED_OFFER_PUBLISHED', 'ACQUISITION_COMMITTED'],
            TradeInOutboxMessage::query()->orderBy('aggregate_version')->pluck('event_type')->all()
        );
        DB::transaction(function (): void {
            $transaction = Transaction::query()->findOrFail(1);
            $transaction->payment_status = 'paid';
            $transaction->save();
            (new TradeInOutboxService())->recordSettlementChange($transaction);
        });
        $this->assertSame(5, TradeInOutboxMessage::query()->count());
        $this->assertSame('SETTLEMENT_UPDATED', TradeInOutboxMessage::query()->latest('aggregate_version')->value('event_type'));
        $this->assertSame('PAID', $cases->projection($first['intake']->fresh())['settlement']['status']);
        try { $cases->publish($this->user(), $first['intake']->fresh()); $this->fail('A decided intake published another offer.'); }
        catch (LogicException $error) { $this->assertStringContainsString('decided', $error->getMessage()); }
        $this->assertSame(1, TradeInApprovedOffer::query()->count());

        $routes = file_get_contents(base_path('Modules/Recommerce/Routes/api.php'));
        $this->assertStringNotContainsString("'/commit'", $routes);
        $this->assertStringContainsString("'/intakes'", $routes);
        $this->app->detectEnvironment(fn (): string => 'production');
        $this->assertSame(404, $middleware->handle(Request::create('/api/trade-in/v2/intakes', 'POST', [], [], [], ['HTTP_AUTHORIZATION' => 'Bearer '.str_repeat('w', 48)]), $next)->getStatusCode());
    }

    public function test_one_saver_value_implementation_prices_indicative_and_native_final_snapshots(): void
    {
        $engine = new SaverValueService();
        $indicative = $engine->indicative([
            'category' => 'LAPTOP', 'model_id' => 'DISC-MODEL-T14-G2', 'model_label' => 'ThinkPad T14 Gen 2',
            'configuration' => ['processor' => 'Intel Core i5', 'ram' => '16GB', 'storage' => '512GB', 'charger' => 'yes'],
            'condition' => ['power' => 'yes', 'screen' => 'minor', 'battery' => 'good', 'physical' => 'good'],
        ]);
        $native = (new TradeInPricingService($engine))->calculate($this->ruleSet(), $this->valuationCommand($this->ruleSet()->id));

        $this->assertSame('SAVER-VALUE-ENGINE-1.0', $indicative['engine_version']);
        $this->assertSame($indicative['engine_version'], $native['engine_version']);
        $this->assertSame('SAVER-VALUE-POLICY-1.0', $indicative['pricing_policy_version']);
        $this->assertSame($indicative['pricing_policy_version'], $native['policy_version']);
        $this->assertSame(93000, $indicative['recommended_acquisition_minor']);
        $this->assertSame(960, $native['recommendation']['economic_ceiling_amount']);

        $changedRule = $this->ruleSet()->replicate();
        $changedRule->id = 999;
        $parameters = $changedRule->parameters_json;
        $parameters['target_margin_percent'] = 0.99;
        $changedRule->parameters_json = $parameters;
        $same = (new TradeInPricingService($engine))->calculate($changedRule, $this->valuationCommand($this->ruleSet()->id));
        $this->assertSame($native['recommendation'], $same['recommendation'], 'Legacy rule parameters must not create a second monetary formula.');
    }

    public function test_trade_in_state_and_outbox_commit_or_roll_back_together(): void
    {
        $attributes = [
            'intake_uuid' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'business_id' => 7,
            'source_system' => 'SAVERBRO_WEBSITE', 'external_case_reference' => 'SB-TI-20260906-10001',
            'submission_id' => 'website-SB-TI-20260906-10001', 'submission_version' => 1,
            'submission_fingerprint' => str_repeat('a', 64), 'category_code' => 'LAPTOP', 'model' => 'Fixture',
            'customer_name' => 'Synthetic', 'customer_email' => 'synthetic@example.test', 'customer_phone' => '0100000000',
            'submitted_at' => now(), 'status' => 'SUBMITTED', 'projection_version' => 1,
        ];
        try {
            DB::transaction(function () use ($attributes): void {
                $intake = TradeInIntake::create($attributes);
                (new TradeInOutboxService())->record($intake, 'INTAKE_ACKNOWLEDGED', [
                    'contract_version' => 'trade-in-pos-authority.v2', 'website_case_reference' => $intake->external_case_reference,
                    'projection_version' => 1,
                ]);
                throw new LogicException('Injected rollback.');
            });
        } catch (LogicException $error) {
            $this->assertSame('Injected rollback.', $error->getMessage());
        }
        $this->assertSame(0, TradeInIntake::query()->count());
        $this->assertSame(0, TradeInOutboxMessage::query()->count());

        DB::transaction(function () use ($attributes): void {
            $intake = TradeInIntake::create($attributes);
            (new TradeInOutboxService())->record($intake, 'INTAKE_ACKNOWLEDGED', [
                'contract_version' => 'trade-in-pos-authority.v2', 'website_case_reference' => $intake->external_case_reference,
                'projection_version' => 1,
            ]);
        });
        $this->assertSame(1, TradeInIntake::query()->count());
        $this->assertSame(1, TradeInOutboxMessage::query()->count());
    }

    public function test_outbox_delivery_retries_authenticates_and_acknowledges_the_same_event(): void
    {
        config(['recommerce.tradein_outbox' => [
            'enabled' => true, 'website_url' => 'http://127.0.0.1/wp-json/saverbro-tradein/v1/integration/projection-events',
            'hmac_secret' => str_repeat('h', 48), 'allowed_hosts' => ['127.0.0.1'],
            'website_basic_authorization' => 'staging-user:staging-password',
            'allow_insecure_local' => true, 'timeout_seconds' => 1,
        ]]);
        $intake = TradeInIntake::create([
            'intake_uuid' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', 'business_id' => 7,
            'source_system' => 'SAVERBRO_WEBSITE', 'external_case_reference' => 'SB-TI-20260906-10002',
            'submission_id' => 'website-SB-TI-20260906-10002', 'submission_version' => 1,
            'submission_fingerprint' => str_repeat('b', 64), 'category_code' => 'LAPTOP', 'model' => 'Fixture',
            'customer_name' => 'Synthetic', 'customer_email' => 'synthetic@example.test', 'customer_phone' => '0100000000',
            'submitted_at' => now(), 'status' => 'SUBMITTED', 'projection_version' => 1,
        ]);
        $message = (new TradeInOutboxService())->record($intake, 'INTAKE_ACKNOWLEDGED', [
            'contract_version' => 'trade-in-pos-authority.v2', 'website_case_reference' => $intake->external_case_reference,
            'projection_version' => 1,
        ]);
        $attempt = 0;
        $seenHeaders = [];
        $dispatcher = new TradeInOutboxDispatcher(function (string $url, array $headers, string $body) use (&$attempt, &$seenHeaders, $message): array {
            $attempt++;
            $seenHeaders = $headers;
            if ($attempt === 1) return ['status' => 503, 'body' => '{}'];
            return ['status' => 200, 'body' => json_encode(['event_id' => $message->event_uuid, 'status' => $attempt === 2 ? 'APPLIED' : 'DUPLICATE'])];
        });
        $this->assertSame('PENDING', $dispatcher->dispatchOne($message));
        $this->assertSame('UPSTREAM', $message->fresh()->last_error_code);
        $this->assertSame(['delivered' => 1, 'pending' => 0, 'failed' => 0], $dispatcher->dispatchPending(1, (string) $message->event_uuid));
        $delivered = $message->fresh();
        $this->assertSame(2, $delivered->attempt_count);
        $this->assertNotNull($delivered->delivered_at);
        $this->assertSame($message->event_uuid, $seenHeaders['X-SaverBro-Event-Id']);
        $this->assertStringStartsWith('sha256=', $seenHeaders['X-SaverBro-Signature']);
        $this->assertSame('Basic '.base64_encode('staging-user:staging-password'), $seenHeaders['Authorization']);
        $payload = json_encode($delivered->payload_json);
        foreach (['customer_email', 'warranty_reserve', 'bearer', 'hmac_secret', 'staff_notes'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $payload);
        }
        $this->assertSame('DELIVERED', $dispatcher->dispatchOne($delivered), 'A delivered event must not be sent again.');
        $this->assertSame(2, $attempt);
    }

    public function test_outbox_classifies_auth_failure_and_allows_explicit_manual_recovery(): void
    {
        config(['recommerce.tradein_outbox' => [
            'enabled' => true, 'website_url' => 'http://127.0.0.1/wp-json/saverbro-tradein/v1/integration/projection-events',
            'hmac_secret' => str_repeat('h', 48), 'allowed_hosts' => ['127.0.0.1'],
            'allow_insecure_local' => true, 'timeout_seconds' => 1,
        ]]);
        $intake = TradeInIntake::create([
            'intake_uuid' => 'cccccccc-cccc-4ccc-8ccc-cccccccccccc', 'business_id' => 7,
            'source_system' => 'SAVERBRO_WEBSITE', 'external_case_reference' => 'SB-TI-20260906-10003',
            'submission_id' => 'website-SB-TI-20260906-10003', 'submission_version' => 1,
            'submission_fingerprint' => str_repeat('c', 64), 'category_code' => 'LAPTOP', 'model' => 'Fixture',
            'customer_name' => 'Synthetic', 'customer_email' => 'synthetic@example.test', 'customer_phone' => '0100000000',
            'submitted_at' => now(), 'status' => 'SUBMITTED', 'projection_version' => 1,
        ]);
        $message = (new TradeInOutboxService())->record($intake, 'INTAKE_ACKNOWLEDGED', [
            'contract_version' => 'trade-in-pos-authority.v2', 'website_case_reference' => $intake->external_case_reference,
            'projection_version' => 1,
        ]);
        $unauthorized = new TradeInOutboxDispatcher(fn (): array => ['status' => 401, 'body' => '{}']);
        $this->assertSame('FAILED', $unauthorized->dispatchOne($message));
        $this->assertSame('AUTHENTICATION', $message->fresh()->last_error_code);

        $recovery = new TradeInOutboxDispatcher(fn (): array => [
            'status' => 200,
            'body' => json_encode(['event_id' => $message->event_uuid, 'status' => 'APPLIED']),
        ]);
        $summary = $recovery->dispatchPending(1, (string) $message->event_uuid);
        $this->assertSame(['delivered' => 1, 'pending' => 0, 'failed' => 0], $summary);
        $this->assertSame('DELIVERED', $message->fresh()->status);
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
