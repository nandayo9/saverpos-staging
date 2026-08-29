<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('recommerce_label_jobs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('job_uuid');
            $table->unsignedInteger('business_id');
            $table->string('label_type', 32);
            $table->string('format', 12);
            $table->string('template_version', 64);
            $table->unsignedInteger('requested_by')->nullable();
            $table->string('status', 24);
            $table->unsignedInteger('item_count')->default(0);
            $table->json('request_json')->nullable();
            $table->string('output_path', 255)->nullable();
            $table->dateTime('expires_at')->nullable();
            $table->string('error_code', 64)->nullable();
            $table->timestamps();

            $table->unique('job_uuid');
            $table->index(['business_id', 'status', 'created_at'], 'recommerce_label_job_status_idx');
            $table->foreign('business_id')->references('id')->on('business')->onDelete('cascade');
            $table->foreign('requested_by')->references('id')->on('users')->onDelete('set null');
        });

        Schema::create('recommerce_label_job_items', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('label_job_id');
            $table->unsignedBigInteger('device_id');
            $table->unsignedBigInteger('scan_token_id');
            $table->unsignedInteger('ordinal');
            $table->string('status', 24);
            $table->string('error_code', 64)->nullable();
            $table->timestamps();

            $table->unique(['label_job_id', 'device_id'], 'recommerce_label_job_device_unique');
            $table->unique(['label_job_id', 'ordinal'], 'recommerce_label_job_ordinal_unique');
            $table->index(['device_id', 'created_at'], 'recommerce_label_job_device_idx');
            $table->foreign('label_job_id')->references('id')->on('recommerce_label_jobs')->onDelete('cascade');
            $table->foreign('device_id')->references('id')->on('recommerce_devices')->onDelete('cascade');
            $table->foreign('scan_token_id')->references('id')->on('recommerce_scan_tokens')->onDelete('restrict');
        });
    }

    public function down()
    {
        Schema::dropIfExists('recommerce_label_job_items');
        Schema::dropIfExists('recommerce_label_jobs');
    }
};
