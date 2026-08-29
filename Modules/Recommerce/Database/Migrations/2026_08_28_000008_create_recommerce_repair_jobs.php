<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('recommerce_repair_jobs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('business_id');
            $table->unsignedInteger('location_id');
            $table->unsignedBigInteger('device_id');
            $table->unsignedInteger('contact_id')->nullable();
            $table->uuid('job_uuid');
            $table->uuid('command_uuid');
            $table->string('job_code', 64);
            $table->string('job_type', 32);
            $table->string('state', 32)->default('RECEIVED');
            $table->string('resolution_code', 32)->nullable();
            $table->string('priority', 20)->default('NORMAL');
            $table->unsignedInteger('assigned_to')->nullable();
            $table->json('intake_snapshot_json')->nullable();
            $table->json('policy_snapshot_json')->nullable();
            $table->string('source_type', 40)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->unsignedInteger('lock_version')->default(1);
            $table->dateTime('opened_at');
            $table->dateTime('closed_at')->nullable();
            $table->unsignedInteger('created_by')->nullable();
            $table->unsignedInteger('updated_by')->nullable();
            $table->timestamps();

            $table->unique('job_uuid');
            $table->unique(['business_id', 'command_uuid'], 'recommerce_repair_job_command_unique');
            $table->unique(['business_id', 'job_code'], 'recommerce_repair_job_code_unique');
            $table->index(['business_id', 'location_id', 'state'], 'recommerce_repair_job_queue_idx');
            $table->index(['business_id', 'device_id', 'state'], 'recommerce_repair_job_device_idx');
            $table->index(['business_id', 'contact_id', 'state'], 'recommerce_repair_job_contact_idx');
            $table->foreign('business_id')->references('id')->on('business')->onDelete('cascade');
            $table->foreign('location_id')->references('id')->on('business_locations')->onDelete('cascade');
            $table->foreign('device_id')->references('id')->on('recommerce_devices')->onDelete('restrict');
            $table->foreign('contact_id')->references('id')->on('contacts')->onDelete('set null');
            $table->foreign('assigned_to')->references('id')->on('users')->onDelete('set null');
            $table->foreign('created_by')->references('id')->on('users')->onDelete('set null');
            $table->foreign('updated_by')->references('id')->on('users')->onDelete('set null');
        });
    }

    public function down()
    {
        Schema::dropIfExists('recommerce_repair_jobs');
    }
};
