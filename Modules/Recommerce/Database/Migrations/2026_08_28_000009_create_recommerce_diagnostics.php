<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('recommerce_diagnostic_templates', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('business_id');
            $table->uuid('template_uuid');
            $table->string('template_code', 64);
            $table->string('name', 160);
            $table->string('category_code', 32)->nullable();
            $table->string('job_type', 32)->nullable();
            $table->string('status', 20)->default('ACTIVE');
            $table->unsignedInteger('created_by')->nullable();
            $table->unsignedInteger('updated_by')->nullable();
            $table->timestamps();

            $table->unique('template_uuid');
            $table->unique(['business_id', 'template_code'], 'recommerce_diagnostic_template_code_unique');
            $table->index(['business_id', 'category_code', 'job_type', 'status'], 'recommerce_diagnostic_template_scope_idx');
            $table->foreign('business_id')->references('id')->on('business')->onDelete('cascade');
            $table->foreign('created_by')->references('id')->on('users')->onDelete('set null');
            $table->foreign('updated_by')->references('id')->on('users')->onDelete('set null');
        });

        Schema::create('recommerce_diagnostic_template_versions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('template_id');
            $table->unsignedInteger('business_id');
            $table->unsignedInteger('version_number');
            $table->string('status', 20)->default('DRAFT');
            $table->json('rubric_json')->nullable();
            $table->dateTime('published_at')->nullable();
            $table->dateTime('retired_at')->nullable();
            $table->unsignedInteger('created_by')->nullable();
            $table->unsignedInteger('published_by')->nullable();
            $table->timestamps();

            $table->unique(['template_id', 'version_number'], 'recommerce_diagnostic_template_version_unique');
            $table->index(['business_id', 'status'], 'recommerce_diagnostic_version_status_idx');
            $table->foreign('template_id')->references('id')->on('recommerce_diagnostic_templates')->onDelete('cascade');
            $table->foreign('business_id')->references('id')->on('business')->onDelete('cascade');
            $table->foreign('created_by')->references('id')->on('users')->onDelete('set null');
            $table->foreign('published_by')->references('id')->on('users')->onDelete('set null');
        });

        Schema::create('recommerce_diagnostic_checks', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('template_version_id');
            $table->unsignedInteger('business_id');
            $table->string('check_key', 64);
            $table->string('label', 160);
            $table->string('outcome_type', 24)->default('STATUS');
            $table->string('unit', 24)->nullable();
            $table->decimal('minimum_value', 22, 4)->nullable();
            $table->decimal('maximum_value', 22, 4)->nullable();
            $table->json('allowed_outcomes_json')->nullable();
            $table->boolean('is_required')->default(true);
            $table->boolean('evidence_required')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['template_version_id', 'check_key'], 'recommerce_diagnostic_check_key_unique');
            $table->index(['business_id', 'template_version_id', 'sort_order'], 'recommerce_diagnostic_check_order_idx');
            $table->foreign('template_version_id')->references('id')->on('recommerce_diagnostic_template_versions')->onDelete('cascade');
            $table->foreign('business_id')->references('id')->on('business')->onDelete('cascade');
        });

        Schema::create('recommerce_diagnostic_sessions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('business_id');
            $table->unsignedInteger('location_id');
            $table->unsignedBigInteger('repair_job_id');
            $table->unsignedBigInteger('template_version_id');
            $table->uuid('session_uuid');
            $table->string('status', 20)->default('DRAFT');
            $table->json('template_snapshot_json');
            $table->string('grade_code', 40)->nullable();
            $table->string('grade_override_reason', 255)->nullable();
            $table->unsignedInteger('started_by')->nullable();
            $table->unsignedInteger('submitted_by')->nullable();
            $table->dateTime('submitted_at')->nullable();
            $table->timestamps();

            $table->unique('session_uuid');
            $table->index(['business_id', 'location_id', 'status'], 'recommerce_diagnostic_session_queue_idx');
            $table->index(['business_id', 'repair_job_id'], 'recommerce_diagnostic_session_job_idx');
            $table->foreign('business_id')->references('id')->on('business')->onDelete('cascade');
            $table->foreign('location_id')->references('id')->on('business_locations')->onDelete('cascade');
            $table->foreign('repair_job_id')->references('id')->on('recommerce_repair_jobs')->onDelete('restrict');
            $table->foreign('template_version_id')->references('id')->on('recommerce_diagnostic_template_versions')->onDelete('restrict');
            $table->foreign('started_by')->references('id')->on('users')->onDelete('set null');
            $table->foreign('submitted_by')->references('id')->on('users')->onDelete('set null');
        });

        Schema::create('recommerce_diagnostic_observations', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('business_id');
            $table->unsignedBigInteger('session_id');
            $table->unsignedBigInteger('diagnostic_check_id')->nullable();
            $table->string('check_key', 64);
            $table->string('outcome', 24);
            $table->decimal('value_numeric', 22, 4)->nullable();
            $table->text('value_text')->nullable();
            $table->text('notes')->nullable();
            $table->json('evidence_json')->nullable();
            $table->unsignedInteger('observed_by')->nullable();
            $table->dateTime('observed_at');
            $table->timestamps();

            $table->unique(['session_id', 'check_key'], 'recommerce_diagnostic_observation_unique');
            $table->index(['business_id', 'session_id']);
            $table->foreign('business_id')->references('id')->on('business')->onDelete('cascade');
            $table->foreign('session_id')->references('id')->on('recommerce_diagnostic_sessions')->onDelete('cascade');
            $table->foreign('diagnostic_check_id')->references('id')->on('recommerce_diagnostic_checks')->onDelete('set null');
            $table->foreign('observed_by')->references('id')->on('users')->onDelete('set null');
        });
    }

    public function down()
    {
        Schema::dropIfExists('recommerce_diagnostic_observations');
        Schema::dropIfExists('recommerce_diagnostic_sessions');
        Schema::dropIfExists('recommerce_diagnostic_checks');
        Schema::dropIfExists('recommerce_diagnostic_template_versions');
        Schema::dropIfExists('recommerce_diagnostic_templates');
    }
};
