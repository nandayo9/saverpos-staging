<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('recommerce_trade_in_catalogue_origins', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('business_id');
            $table->unsignedInteger('location_id')->nullable();
            $table->unsignedInteger('product_id');
            $table->unsignedInteger('variation_id');
            $table->string('catalogue_origin', 32)->default('TRADE_IN');
            $table->string('specification_fingerprint', 128);
            $table->json('specifications_json');
            $table->unsignedBigInteger('created_from_quick_quote_id')->nullable();
            $table->unsignedBigInteger('created_from_trade_in_id')->nullable();
            $table->unsignedInteger('created_by')->nullable();
            $table->timestamps();

            $table->unique(['business_id', 'variation_id'], 'rc_ti_catalogue_origin_variation_unique');
            $table->index(['business_id', 'catalogue_origin', 'specification_fingerprint'], 'rc_ti_catalogue_origin_lookup_idx');
            $table->foreign('business_id')->references('id')->on('business')->onDelete('cascade');
            $table->foreign('location_id')->references('id')->on('business_locations')->onDelete('set null');
            $table->foreign('product_id')->references('id')->on('products')->onDelete('restrict');
            $table->foreign('variation_id')->references('id')->on('variations')->onDelete('restrict');
            $table->foreign('created_from_quick_quote_id', 'rc_ti_catalogue_origin_quote_fk')->references('id')->on('recommerce_trade_in_quick_quotes')->onDelete('set null');
            $table->foreign('created_from_trade_in_id', 'rc_ti_catalogue_origin_valuation_fk')->references('id')->on('recommerce_trade_in_valuations')->onDelete('set null');
            $table->foreign('created_by')->references('id')->on('users')->onDelete('set null');
        });
    }

    public function down()
    {
        Schema::dropIfExists('recommerce_trade_in_catalogue_origins');
    }
};
