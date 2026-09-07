<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('recommerce_trade_in_photo_ai_analyses', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('analysis_uuid');
            $table->unsignedBigInteger('intake_id');
            $table->unsignedInteger('analysis_version');
            $table->string('provider', 80);
            $table->string('model_version', 100);
            $table->string('schema_version', 16);
            $table->string('status', 24);
            $table->json('evidence_ids_json');
            $table->json('result_json');
            $table->json('customer_confirmation_json');
            $table->json('confirmed_condition_json');
            $table->string('catalogue_match_status', 24)->default('NOT_ATTEMPTED');
            $table->unsignedInteger('matched_product_id')->nullable();
            $table->unsignedInteger('matched_variation_id')->nullable();
            $table->json('catalogue_candidates_json')->nullable();
            $table->string('identity_resolution_status', 32)->default('NOT_ATTEMPTED');
            $table->unsignedBigInteger('resolved_device_id')->nullable();
            $table->json('identity_resolution_json')->nullable();
            $table->decimal('overall_confidence', 5, 4)->nullable();
            $table->timestamps();

            $table->unique('analysis_uuid', 'rc_ti_photo_ai_uuid_unique');
            $table->unique(['intake_id', 'analysis_version'], 'rc_ti_photo_ai_version_unique');
            $table->foreign('intake_id', 'rc_ti_ai_intake_fk')->references('id')->on('recommerce_trade_in_intakes')->onDelete('restrict');
            $table->foreign('matched_product_id', 'rc_ti_ai_product_fk')->references('id')->on('products')->onDelete('restrict');
            $table->foreign('matched_variation_id', 'rc_ti_ai_variation_fk')->references('id')->on('variations')->onDelete('restrict');
            $table->foreign('resolved_device_id', 'rc_ti_ai_device_fk')->references('id')->on('recommerce_devices')->onDelete('restrict');
        });

        Schema::create('recommerce_trade_in_photo_ai_reviews', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('analysis_id');
            $table->unsignedInteger('reviewed_by');
            $table->json('technician_findings_json');
            $table->json('disagreements_json');
            $table->dateTime('reviewed_at');
            $table->timestamps();

            $table->unique('analysis_id', 'rc_ti_photo_ai_review_unique');
            $table->foreign('analysis_id', 'rc_ti_ai_review_analysis_fk')->references('id')->on('recommerce_trade_in_photo_ai_analyses')->onDelete('restrict');
            $table->foreign('reviewed_by', 'rc_ti_ai_review_user_fk')->references('id')->on('users')->onDelete('restrict');
        });
    }

    public function down()
    {
        Schema::dropIfExists('recommerce_trade_in_photo_ai_reviews');
        Schema::dropIfExists('recommerce_trade_in_photo_ai_analyses');
    }
};
