<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('recommerce_trade_in_rule_sets', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('business_id');
            $table->string('rule_code', 64);
            $table->unsignedInteger('version_number');
            $table->string('status', 20)->default('ACTIVE');
            $table->json('parameters_json');
            $table->unsignedInteger('created_by')->nullable();
            $table->unsignedInteger('retired_by')->nullable();
            $table->dateTime('effective_at');
            $table->dateTime('retired_at')->nullable();
            $table->timestamps();

            $table->unique(['business_id', 'rule_code', 'version_number'], 'rc_tradein_rule_version_unique');
            $table->index(['business_id', 'status', 'effective_at'], 'rc_tradein_rule_active_idx');
            $table->foreign('business_id')->references('id')->on('business')->onDelete('cascade');
            $table->foreign('created_by')->references('id')->on('users')->onDelete('set null');
            $table->foreign('retired_by')->references('id')->on('users')->onDelete('set null');
        });

        Schema::create('recommerce_trade_in_valuations', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('valuation_uuid');
            $table->uuid('command_uuid')->nullable();
            $table->unsignedInteger('business_id');
            $table->unsignedInteger('location_id');
            $table->unsignedBigInteger('device_id');
            $table->unsignedInteger('customer_contact_id');
            $table->unsignedInteger('supplier_contact_id');
            $table->unsignedInteger('product_id');
            $table->unsignedInteger('variation_id');
            $table->unsignedBigInteger('rule_set_id');
            $table->unsignedInteger('version_number')->default(1);
            $table->unsignedBigInteger('supersedes_valuation_id')->nullable();
            $table->string('status', 32)->default('READY_TO_ACCEPT');
            $table->json('inspection_json');
            $table->json('pricing_snapshot_json');
            $table->decimal('market_low_amount', 22, 4)->nullable();
            $table->decimal('market_high_amount', 22, 4)->nullable();
            $table->decimal('market_reference_amount', 22, 4);
            $table->decimal('expected_resale_amount', 22, 4);
            $table->decimal('expected_refurbishment_amount', 22, 4)->default(0);
            $table->decimal('opening_offer_amount', 22, 4);
            $table->decimal('target_acquisition_amount', 22, 4);
            $table->decimal('negotiation_ceiling_amount', 22, 4);
            $table->decimal('economic_ceiling_amount', 22, 4);
            $table->decimal('staff_proposed_amount', 22, 4);
            $table->decimal('customer_requested_amount', 22, 4)->nullable();
            $table->decimal('final_acquisition_amount', 22, 4)->nullable();
            $table->string('currency', 12)->default('MYR');
            $table->boolean('approval_required')->default(false);
            $table->unsignedInteger('approved_by')->nullable();
            $table->dateTime('approved_at')->nullable();
            $table->text('approval_reason')->nullable();
            $table->dateTime('accepted_at')->nullable();
            $table->dateTime('rejected_at')->nullable();
            $table->unsignedInteger('rejected_by')->nullable();
            $table->string('rejection_reason', 255)->nullable();
            $table->unsignedInteger('lock_version')->default(1);
            $table->unsignedInteger('created_by')->nullable();
            $table->timestamps();

            $table->unique('valuation_uuid', 'rc_tradein_valuation_uuid_unique');
            $table->unique(['business_id', 'command_uuid'], 'rc_tradein_valuation_command_unique');
            $table->index(['business_id', 'location_id', 'status'], 'rc_tradein_valuation_queue_idx');
            $table->index(['business_id', 'device_id', 'created_at'], 'rc_tradein_valuation_device_idx');
            $table->foreign('business_id')->references('id')->on('business')->onDelete('cascade');
            $table->foreign('location_id')->references('id')->on('business_locations')->onDelete('cascade');
            $table->foreign('device_id')->references('id')->on('recommerce_devices')->onDelete('restrict');
            $table->foreign('customer_contact_id', 'rc_tradein_customer_fk')->references('id')->on('contacts')->onDelete('restrict');
            $table->foreign('supplier_contact_id', 'rc_tradein_supplier_fk')->references('id')->on('contacts')->onDelete('restrict');
            $table->foreign('product_id')->references('id')->on('products')->onDelete('restrict');
            $table->foreign('variation_id')->references('id')->on('variations')->onDelete('restrict');
            $table->foreign('rule_set_id')->references('id')->on('recommerce_trade_in_rule_sets')->onDelete('restrict');
            $table->foreign('supersedes_valuation_id', 'rc_tradein_supersedes_fk')->references('id')->on('recommerce_trade_in_valuations')->onDelete('restrict');
            $table->foreign('approved_by')->references('id')->on('users')->onDelete('set null');
            $table->foreign('rejected_by')->references('id')->on('users')->onDelete('set null');
            $table->foreign('created_by')->references('id')->on('users')->onDelete('set null');
        });

        Schema::create('recommerce_trade_in_market_evidence', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('valuation_id');
            $table->unsignedInteger('business_id');
            $table->string('evidence_type', 32)->default('MARKETPLACE');
            $table->decimal('reference_amount', 22, 4);
            $table->string('currency', 12)->default('MYR');
            $table->string('source_description', 320);
            $table->string('reference_url', 1000)->nullable();
            $table->dateTime('observed_at');
            $table->unsignedInteger('recorded_by')->nullable();
            $table->timestamps();

            $table->index(['valuation_id', 'observed_at'], 'rc_tradein_market_evidence_idx');
            $table->foreign('valuation_id')->references('id')->on('recommerce_trade_in_valuations')->onDelete('cascade');
            $table->foreign('business_id')->references('id')->on('business')->onDelete('cascade');
            $table->foreign('recorded_by')->references('id')->on('users')->onDelete('set null');
        });

        Schema::create('recommerce_device_acquisitions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('acquisition_uuid');
            $table->uuid('command_uuid');
            $table->unsignedInteger('business_id');
            $table->unsignedBigInteger('device_id');
            $table->unsignedBigInteger('trade_in_valuation_id')->nullable();
            $table->unsignedInteger('seller_contact_id')->nullable();
            $table->unsignedInteger('supplier_contact_id');
            $table->unsignedInteger('location_id');
            $table->string('acquisition_source', 32);
            $table->unsignedInteger('transaction_id');
            $table->unsignedInteger('purchase_line_id');
            $table->decimal('acquisition_amount', 22, 4);
            $table->string('currency', 12)->default('MYR');
            $table->dateTime('posted_at');
            $table->unsignedInteger('recorded_by')->nullable();
            $table->timestamps();

            $table->unique('acquisition_uuid', 'rc_device_acquisition_uuid_unique');
            $table->unique(['business_id', 'command_uuid'], 'rc_device_acquisition_command_unique');
            $table->unique('trade_in_valuation_id', 'rc_device_acquisition_valuation_unique');
            $table->unique(['transaction_id', 'purchase_line_id'], 'rc_device_acquisition_source_unique');
            $table->index(['business_id', 'device_id', 'posted_at'], 'rc_device_acquisition_device_idx');
            $table->foreign('business_id')->references('id')->on('business')->onDelete('cascade');
            $table->foreign('device_id')->references('id')->on('recommerce_devices')->onDelete('restrict');
            $table->foreign('trade_in_valuation_id')->references('id')->on('recommerce_trade_in_valuations')->onDelete('restrict');
            $table->foreign('seller_contact_id')->references('id')->on('contacts')->onDelete('set null');
            $table->foreign('supplier_contact_id', 'rc_device_acquisition_supplier_fk')->references('id')->on('contacts')->onDelete('restrict');
            $table->foreign('location_id')->references('id')->on('business_locations')->onDelete('restrict');
            $table->foreign('transaction_id')->references('id')->on('transactions')->onDelete('restrict');
            $table->foreign('purchase_line_id')->references('id')->on('purchase_lines')->onDelete('restrict');
            $table->foreign('recorded_by')->references('id')->on('users')->onDelete('set null');
        });

        Schema::create('recommerce_device_acquisition_reversals', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('command_uuid');
            $table->unsignedInteger('business_id');
            $table->unsignedBigInteger('acquisition_id');
            $table->unsignedInteger('reversal_transaction_id');
            $table->string('reason', 255);
            $table->unsignedInteger('recorded_by')->nullable();
            $table->dateTime('reversed_at');
            $table->timestamps();

            $table->unique(['business_id', 'command_uuid'], 'rc_device_acq_reversal_command_unique');
            $table->unique('acquisition_id', 'rc_device_acq_reversal_acquisition_unique');
            $table->unique('reversal_transaction_id', 'rc_device_acq_reversal_tx_unique');
            $table->foreign('business_id', 'rc_acq_reversal_business_fk')->references('id')->on('business')->onDelete('cascade');
            $table->foreign('acquisition_id', 'rc_acq_reversal_acquisition_fk')->references('id')->on('recommerce_device_acquisitions')->onDelete('restrict');
            $table->foreign('reversal_transaction_id', 'rc_acq_reversal_transaction_fk')->references('id')->on('transactions')->onDelete('restrict');
            $table->foreign('recorded_by', 'rc_acq_reversal_recorded_by_fk')->references('id')->on('users')->onDelete('set null');
        });
    }

    public function down()
    {
        Schema::dropIfExists('recommerce_device_acquisition_reversals');
        Schema::dropIfExists('recommerce_device_acquisitions');
        Schema::dropIfExists('recommerce_trade_in_market_evidence');
        Schema::dropIfExists('recommerce_trade_in_valuations');
        Schema::dropIfExists('recommerce_trade_in_rule_sets');
    }
};
