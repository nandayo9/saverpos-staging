<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('recommerce_trade_in_intakes', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('intake_uuid');
            $table->unsignedInteger('business_id');
            $table->string('source_system', 32);
            $table->string('external_case_reference', 64);
            $table->string('submission_id', 80);
            $table->unsignedInteger('submission_version');
            $table->char('submission_fingerprint', 64);
            $table->string('category_code', 32);
            $table->string('brand', 100)->nullable();
            $table->string('model', 255);
            $table->json('specifications_json')->nullable();
            $table->json('declared_condition_json')->nullable();
            $table->json('indicative_snapshot_json')->nullable();
            $table->json('evidence_references_json')->nullable();
            $table->string('customer_name', 255);
            $table->string('customer_email', 255);
            $table->string('customer_phone', 80);
            $table->string('preferred_branch', 160)->nullable();
            $table->dateTime('submitted_at');
            $table->unsignedInteger('location_id')->nullable();
            $table->unsignedBigInteger('valuation_id')->nullable();
            $table->string('status', 40)->default('SUBMITTED');
            $table->unsignedInteger('projection_version')->default(1);
            $table->timestamps();

            $table->unique('intake_uuid', 'rc_ti_intake_uuid_unique');
            $table->unique(['business_id', 'source_system', 'external_case_reference'], 'rc_ti_intake_source_case_unique');
            $table->unique(['business_id', 'source_system', 'submission_id'], 'rc_ti_intake_submission_unique');
            $table->index(['business_id', 'status', 'submitted_at'], 'rc_ti_intake_queue_idx');
            $table->foreign('business_id')->references('id')->on('business')->onDelete('cascade');
            $table->foreign('location_id')->references('id')->on('business_locations')->onDelete('restrict');
            $table->foreign('valuation_id')->references('id')->on('recommerce_trade_in_valuations')->onDelete('restrict');
        });

        Schema::create('recommerce_trade_in_approved_offers', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('offer_uuid');
            $table->unsignedBigInteger('intake_id');
            $table->unsignedBigInteger('valuation_id');
            $table->unsignedInteger('offer_version');
            $table->decimal('amount', 22, 4);
            $table->string('currency', 12)->default('MYR');
            $table->json('customer_projection_json');
            $table->string('status', 24)->default('PUBLISHED');
            $table->unsignedInteger('approved_by');
            $table->dateTime('approved_at');
            $table->dateTime('published_at');
            $table->timestamps();

            $table->unique('offer_uuid', 'rc_ti_offer_uuid_unique');
            $table->unique(['intake_id', 'offer_version'], 'rc_ti_offer_version_unique');
            $table->index(['intake_id', 'status'], 'rc_ti_offer_status_idx');
            $table->foreign('intake_id')->references('id')->on('recommerce_trade_in_intakes')->onDelete('restrict');
            $table->foreign('valuation_id')->references('id')->on('recommerce_trade_in_valuations')->onDelete('restrict');
            $table->foreign('approved_by')->references('id')->on('users')->onDelete('restrict');
        });

        Schema::create('recommerce_trade_in_customer_decisions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('decision_uuid');
            $table->uuid('idempotency_key');
            $table->char('request_fingerprint', 64);
            $table->unsignedBigInteger('intake_id');
            $table->unsignedBigInteger('offer_id');
            $table->string('decision', 16);
            $table->unsignedBigInteger('acquisition_id')->nullable();
            $table->dateTime('decided_at');
            $table->timestamps();

            $table->unique('decision_uuid', 'rc_ti_decision_uuid_unique');
            $table->unique(['intake_id', 'idempotency_key'], 'rc_ti_decision_idempotency_unique');
            $table->unique('offer_id', 'rc_ti_decision_offer_unique');
            $table->foreign('intake_id')->references('id')->on('recommerce_trade_in_intakes')->onDelete('restrict');
            $table->foreign('offer_id')->references('id')->on('recommerce_trade_in_approved_offers')->onDelete('restrict');
            $table->foreign('acquisition_id')->references('id')->on('recommerce_device_acquisitions')->onDelete('restrict');
        });
    }

    public function down()
    {
        Schema::dropIfExists('recommerce_trade_in_customer_decisions');
        Schema::dropIfExists('recommerce_trade_in_approved_offers');
        Schema::dropIfExists('recommerce_trade_in_intakes');
    }
};
