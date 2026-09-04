<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('recommerce_trade_in_quick_quotes', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('quote_uuid');
            $table->uuid('command_uuid');
            $table->unsignedInteger('business_id');
            $table->unsignedInteger('location_id');
            $table->unsignedInteger('customer_contact_id')->nullable();
            $table->unsignedInteger('product_id');
            $table->unsignedInteger('variation_id');
            $table->unsignedBigInteger('rule_set_id');
            $table->unsignedBigInteger('supersedes_quote_id')->nullable();
            $table->unsignedBigInteger('continued_to_valuation_id')->nullable();
            $table->string('status', 32)->default('CONSIDERING');
            $table->string('acquisition_type', 32)->default('SELL_TO_SAVERBRO');
            $table->string('seller_name_snapshot', 255)->nullable();
            $table->string('seller_phone_snapshot', 80)->nullable();
            $table->json('specifications_json');
            $table->json('condition_json');
            $table->decimal('customer_expected_amount', 22, 4)->nullable();
            $table->boolean('customer_expected_unknown')->default(false);
            $table->decimal('expected_resale_amount', 22, 4);
            $table->json('pricing_snapshot_json');
            $table->decimal('estimated_low_amount', 22, 4);
            $table->decimal('estimated_high_amount', 22, 4);
            $table->string('lost_reason_code', 48)->nullable();
            $table->string('lost_reason', 255)->nullable();
            $table->dateTime('expires_at');
            $table->unsignedInteger('created_by')->nullable();
            $table->timestamps();

            $table->unique('quote_uuid', 'rc_tradein_quick_quote_uuid_unique');
            $table->unique(['business_id', 'command_uuid'], 'rc_tradein_quick_quote_command_unique');
            $table->index(['business_id', 'location_id', 'status', 'expires_at'], 'rc_tradein_quick_quote_queue_idx');
            $table->foreign('business_id', 'rc_ti_qq_business_fk')->references('id')->on('business')->onDelete('cascade');
            $table->foreign('location_id', 'rc_ti_qq_location_fk')->references('id')->on('business_locations')->onDelete('cascade');
            $table->foreign('customer_contact_id', 'rc_ti_qq_customer_fk')->references('id')->on('contacts')->onDelete('set null');
            $table->foreign('product_id', 'rc_ti_qq_product_fk')->references('id')->on('products')->onDelete('restrict');
            $table->foreign('variation_id', 'rc_ti_qq_variation_fk')->references('id')->on('variations')->onDelete('restrict');
            $table->foreign('rule_set_id', 'rc_ti_qq_rule_fk')->references('id')->on('recommerce_trade_in_rule_sets')->onDelete('restrict');
            $table->foreign('supersedes_quote_id', 'rc_ti_qq_supersedes_fk')->references('id')->on('recommerce_trade_in_quick_quotes')->onDelete('restrict');
            $table->foreign('continued_to_valuation_id', 'rc_ti_qq_valuation_fk')->references('id')->on('recommerce_trade_in_valuations')->onDelete('set null');
            $table->foreign('created_by', 'rc_ti_qq_creator_fk')->references('id')->on('users')->onDelete('set null');
        });
    }

    public function down()
    {
        Schema::dropIfExists('recommerce_trade_in_quick_quotes');
    }
};
