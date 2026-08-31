<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('recommerce_trade_in_rule_sets', function (Blueprint $table) {
            $table->unsignedInteger('variation_id')->nullable()->after('business_id');
            $table->string('category_code', 32)->nullable()->after('variation_id');
            $table->index(['business_id', 'variation_id', 'status'], 'rc_tradein_rule_variation_idx');
        });

        Schema::table('recommerce_trade_in_valuations', function (Blueprint $table) {
            $table->string('acquisition_type', 32)->default('TRADE_IN')->after('currency');
            $table->decimal('authority_limit_amount', 22, 4)->nullable()->after('approval_required');
            $table->boolean('authority_approval_required')->default(false)->after('authority_limit_amount');
            $table->string('seller_phone_snapshot', 80)->nullable()->after('supplier_contact_id');
            $table->text('seller_identity_reference_encrypted')->nullable()->after('seller_phone_snapshot');
            $table->text('seller_declaration_text')->nullable()->after('seller_identity_reference_encrypted');
            $table->string('seller_declaration_version', 32)->nullable()->after('seller_declaration_text');
            $table->dateTime('seller_declaration_accepted_at')->nullable()->after('seller_declaration_version');
            $table->string('rejection_reason_code', 48)->nullable()->after('rejection_reason');
            $table->string('competitor_name', 160)->nullable()->after('rejection_reason_code');
            $table->decimal('competitor_offer_amount', 22, 4)->nullable()->after('competitor_name');
            $table->index(['business_id', 'location_id', 'acquisition_type', 'status'], 'rc_tradein_v2_queue_idx');
        });

        Schema::create('recommerce_trade_in_seller_representations', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('business_id');
            $table->unsignedInteger('customer_contact_id');
            $table->unsignedInteger('supplier_contact_id');
            $table->unsignedInteger('created_by')->nullable();
            $table->timestamps();
            $table->unique(['business_id', 'customer_contact_id'], 'rc_tradein_seller_customer_unique');
            $table->unique(['business_id', 'supplier_contact_id'], 'rc_tradein_seller_supplier_unique');
            $table->foreign('business_id', 'rc_ti_seller_business_fk')->references('id')->on('business')->onDelete('cascade');
            $table->foreign('customer_contact_id', 'rc_ti_seller_customer_fk')->references('id')->on('contacts')->onDelete('restrict');
            $table->foreign('supplier_contact_id', 'rc_ti_seller_supplier_fk')->references('id')->on('contacts')->onDelete('restrict');
            $table->foreign('created_by', 'rc_ti_seller_creator_fk')->references('id')->on('users')->onDelete('set null');
        });

        Schema::create('recommerce_trade_in_laptop_inspections', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('valuation_id');
            $table->unsignedInteger('business_id');
            $table->string('brand', 100);
            $table->string('model', 160);
            $table->string('cpu', 160)->nullable();
            $table->string('ram', 80)->nullable();
            $table->string('storage', 120)->nullable();
            $table->string('gpu', 160)->nullable();
            $table->string('display_size', 40)->nullable();
            $table->string('operating_system', 120)->nullable();
            $table->string('cosmetic_grade', 4);
            $table->string('screen_condition', 24)->nullable();
            $table->string('body_condition', 24)->nullable();
            $table->string('palm_rest_condition', 24)->nullable();
            $table->string('keyboard_condition', 24)->nullable();
            $table->string('hinges_condition', 24)->nullable();
            $table->json('functional_checks_json');
            $table->decimal('battery_health_percent', 5, 2)->nullable();
            $table->unsignedInteger('battery_cycle_count')->nullable();
            $table->string('battery_replacement_needed', 16)->default('NO');
            $table->decimal('battery_replacement_estimate_amount', 22, 4)->default(0);
            $table->json('accessories_json')->nullable();
            $table->json('risk_flags_json')->nullable();
            $table->unsignedInteger('recorded_by')->nullable();
            $table->timestamps();
            $table->unique('valuation_id', 'rc_tradein_laptop_valuation_unique');
            $table->foreign('valuation_id', 'rc_ti_laptop_valuation_fk')->references('id')->on('recommerce_trade_in_valuations')->onDelete('cascade');
            $table->foreign('business_id', 'rc_ti_laptop_business_fk')->references('id')->on('business')->onDelete('cascade');
            $table->foreign('recorded_by', 'rc_ti_laptop_recorder_fk')->references('id')->on('users')->onDelete('set null');
        });

        Schema::create('recommerce_trade_in_negotiation_events', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('event_uuid');
            $table->unsignedBigInteger('valuation_id');
            $table->unsignedInteger('business_id');
            $table->string('event_type', 40);
            $table->string('actor_type', 24);
            $table->decimal('amount', 22, 4)->nullable();
            $table->string('currency', 12)->default('MYR');
            $table->string('note', 1000)->nullable();
            $table->unsignedInteger('recorded_by')->nullable();
            $table->dateTime('occurred_at');
            $table->timestamps();
            $table->unique('event_uuid', 'rc_tradein_negotiation_uuid_unique');
            $table->index(['valuation_id', 'occurred_at'], 'rc_tradein_negotiation_timeline_idx');
            $table->foreign('valuation_id', 'rc_ti_event_valuation_fk')->references('id')->on('recommerce_trade_in_valuations')->onDelete('cascade');
            $table->foreign('business_id', 'rc_ti_event_business_fk')->references('id')->on('business')->onDelete('cascade');
            $table->foreign('recorded_by', 'rc_ti_event_recorder_fk')->references('id')->on('users')->onDelete('set null');
        });

        Schema::create('recommerce_trade_in_authority_rules', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('business_id');
            $table->unsignedInteger('location_id')->nullable();
            $table->string('role_name', 160)->nullable();
            $table->decimal('maximum_without_approval', 22, 4);
            $table->boolean('active')->default(true);
            $table->unsignedInteger('created_by')->nullable();
            $table->timestamps();
            $table->index(['business_id', 'location_id', 'active'], 'rc_tradein_authority_scope_idx');
            $table->foreign('business_id', 'rc_ti_authority_business_fk')->references('id')->on('business')->onDelete('cascade');
            $table->foreign('location_id', 'rc_ti_authority_location_fk')->references('id')->on('business_locations')->onDelete('cascade');
            $table->foreign('created_by', 'rc_ti_authority_creator_fk')->references('id')->on('users')->onDelete('set null');
        });

        Schema::create('recommerce_trade_in_photos', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('device_id');
            $table->unsignedBigInteger('valuation_id')->nullable();
            $table->unsignedInteger('media_id');
            $table->string('purpose', 40);
            $table->unsignedInteger('captured_by')->nullable();
            $table->dateTime('captured_at');
            $table->timestamps();
            $table->index(['device_id', 'purpose'], 'rc_tradein_photo_device_idx');
            $table->foreign('device_id', 'rc_ti_photo_device_fk')->references('id')->on('recommerce_devices')->onDelete('cascade');
            $table->foreign('valuation_id', 'rc_ti_photo_valuation_fk')->references('id')->on('recommerce_trade_in_valuations')->onDelete('cascade');
            $table->foreign('captured_by', 'rc_ti_photo_capturer_fk')->references('id')->on('users')->onDelete('set null');
        });

        Schema::create('recommerce_trade_in_sale_links', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('acquisition_id');
            $table->unsignedInteger('sale_transaction_id');
            $table->decimal('applied_amount', 22, 4);
            $table->string('status', 24)->default('PLANNED');
            $table->unsignedInteger('created_by')->nullable();
            $table->timestamps();
            $table->unique('acquisition_id', 'rc_tradein_sale_link_acquisition_unique');
            $table->foreign('acquisition_id', 'rc_ti_sale_acquisition_fk')->references('id')->on('recommerce_device_acquisitions')->onDelete('restrict');
            $table->foreign('sale_transaction_id', 'rc_ti_sale_transaction_fk')->references('id')->on('transactions')->onDelete('restrict');
            $table->foreign('created_by', 'rc_ti_sale_creator_fk')->references('id')->on('users')->onDelete('set null');
        });
    }

    public function down()
    {
        Schema::dropIfExists('recommerce_trade_in_sale_links');
        Schema::dropIfExists('recommerce_trade_in_photos');
        Schema::dropIfExists('recommerce_trade_in_authority_rules');
        Schema::dropIfExists('recommerce_trade_in_negotiation_events');
        Schema::dropIfExists('recommerce_trade_in_laptop_inspections');
        Schema::dropIfExists('recommerce_trade_in_seller_representations');
        Schema::table('recommerce_trade_in_valuations', function (Blueprint $table) {
            $table->dropIndex('rc_tradein_v2_queue_idx');
            $table->dropColumn(['acquisition_type', 'authority_limit_amount', 'authority_approval_required', 'seller_phone_snapshot', 'seller_identity_reference_encrypted', 'seller_declaration_text', 'seller_declaration_version', 'seller_declaration_accepted_at', 'rejection_reason_code', 'competitor_name', 'competitor_offer_amount']);
        });
        Schema::table('recommerce_trade_in_rule_sets', function (Blueprint $table) {
            $table->dropIndex('rc_tradein_rule_variation_idx');
            $table->dropColumn(['variation_id', 'category_code']);
        });
    }
};
