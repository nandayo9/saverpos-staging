<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('recommerce_warranty_claims', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('business_id');
            $table->unsignedInteger('location_id');
            $table->uuid('claim_uuid');
            $table->uuid('command_uuid');
            $table->string('claim_number', 64);
            $table->unsignedBigInteger('repair_job_id')->nullable();
            $table->unsignedBigInteger('source_repair_job_id');
            $table->unsignedBigInteger('device_id');
            $table->unsignedInteger('warranty_id')->nullable()->index();
            $table->string('coverage_status', 32)->default('PENDING_DECISION');
            $table->string('decision_reason', 255);
            $table->string('policy_name', 160)->nullable();
            $table->unsignedSmallInteger('policy_version')->nullable();
            $table->json('policy_snapshot_json');
            $table->json('decision_evidence_json');
            $table->dateTime('coverage_start_at')->nullable();
            $table->dateTime('coverage_end_at')->nullable();
            $table->dateTime('claim_requested_at');
            $table->date('claimed_on')->nullable();
            $table->unsignedInteger('created_by')->nullable();
            $table->unsignedInteger('updated_by')->nullable();
            $table->timestamps();

            $table->unique('claim_uuid');
            $table->unique(['business_id', 'command_uuid'], 'recommerce_warranty_claim_command_unique');
            $table->unique(['business_id', 'claim_number'], 'recommerce_warranty_claim_number_unique');
            $table->index(['business_id', 'device_id', 'coverage_status'], 'recommerce_warranty_claim_device_idx');
            $table->foreign('business_id')->references('id')->on('business')->onDelete('cascade');
            $table->foreign('location_id')->references('id')->on('business_locations')->onDelete('cascade');
            $table->foreign('repair_job_id')->references('id')->on('recommerce_repair_jobs')->onDelete('restrict');
            $table->foreign('source_repair_job_id')->references('id')->on('recommerce_repair_jobs')->onDelete('restrict');
            $table->foreign('device_id')->references('id')->on('recommerce_devices')->onDelete('restrict');
            $table->foreign('warranty_id')->references('id')->on('warranties')->onDelete('restrict');
            $table->foreign('created_by')->references('id')->on('users')->onDelete('set null');
            $table->foreign('updated_by')->references('id')->on('users')->onDelete('set null');
        });

        Schema::create('recommerce_warranty_claim_lines', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('warranty_claim_id');
            $table->unsignedInteger('business_id');
            $table->unsignedInteger('location_id');
            $table->string('line_type', 24);
            $table->string('billing_treatment', 24);
            $table->string('description', 255);
            $table->decimal('amount', 22, 4)->default(0);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['warranty_claim_id', 'billing_treatment', 'sort_order'], 'recommerce_warranty_claim_line_idx');
            $table->foreign('warranty_claim_id')->references('id')->on('recommerce_warranty_claims')->onDelete('cascade');
            $table->foreign('business_id')->references('id')->on('business')->onDelete('cascade');
            $table->foreign('location_id')->references('id')->on('business_locations')->onDelete('cascade');
        });
    }

    public function down()
    {
        Schema::dropIfExists('recommerce_warranty_claim_lines');
        Schema::dropIfExists('recommerce_warranty_claims');
    }
};
