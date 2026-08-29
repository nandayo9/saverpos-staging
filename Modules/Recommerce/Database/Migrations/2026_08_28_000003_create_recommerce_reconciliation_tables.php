<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('recommerce_reconciliation_runs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('run_uuid');
            $table->unsignedInteger('business_id');
            $table->unsignedInteger('location_id');
            $table->unsignedInteger('variation_id');
            $table->unsignedInteger('requested_by')->nullable();
            $table->dateTime('as_of');
            $table->string('status', 24);
            $table->string('evidence_status', 40);
            $table->decimal('core_quantity', 22, 4)->nullable();
            $table->unsignedInteger('tracked_device_count');
            $table->unsignedInteger('in_transfer_device_count');
            $table->decimal('approved_legacy_balance', 22, 4)->nullable();
            $table->decimal('difference', 22, 4)->nullable();
            $table->string('result_hash', 64);
            $table->json('snapshot_json');
            $table->timestamps();

            $table->unique('run_uuid');
            $table->index(['business_id', 'location_id', 'variation_id', 'as_of'], 'recommerce_reconciliation_scope_idx');
            $table->index(['business_id', 'status', 'as_of'], 'recommerce_reconciliation_status_idx');
            $table->foreign('business_id')->references('id')->on('business')->onDelete('cascade');
            $table->foreign('location_id')->references('id')->on('business_locations')->onDelete('cascade');
            $table->foreign('variation_id')->references('id')->on('variations')->onDelete('cascade');
            $table->foreign('requested_by')->references('id')->on('users')->onDelete('set null');
        });

        Schema::create('recommerce_reconciliation_issues', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('reconciliation_run_id');
            $table->unsignedInteger('business_id');
            $table->unsignedInteger('location_id');
            $table->unsignedInteger('variation_id');
            $table->string('issue_type', 32);
            $table->string('severity', 20);
            $table->string('status', 20)->default('OPEN');
            $table->dateTime('detected_at');
            $table->json('snapshot_json');
            $table->string('resolution_reference', 160)->nullable();
            $table->dateTime('resolved_at')->nullable();
            $table->timestamps();

            $table->unique('reconciliation_run_id', 'recommerce_reconciliation_issue_run_unique');
            $table->index(['business_id', 'location_id', 'variation_id', 'status'], 'recommerce_reconciliation_issue_scope_idx');
            $table->foreign('reconciliation_run_id')->references('id')->on('recommerce_reconciliation_runs')->onDelete('cascade');
            $table->foreign('business_id')->references('id')->on('business')->onDelete('cascade');
            $table->foreign('location_id')->references('id')->on('business_locations')->onDelete('cascade');
            $table->foreign('variation_id')->references('id')->on('variations')->onDelete('cascade');
        });
    }

    public function down()
    {
        Schema::dropIfExists('recommerce_reconciliation_issues');
        Schema::dropIfExists('recommerce_reconciliation_runs');
    }
};
