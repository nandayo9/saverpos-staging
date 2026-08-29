<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('recommerce_repair_part_reservations', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('business_id');
            $table->unsignedInteger('location_id');
            $table->unsignedBigInteger('repair_job_id');
            $table->unsignedInteger('product_id');
            $table->unsignedInteger('variation_id');
            $table->uuid('command_uuid');
            $table->decimal('quantity', 22, 4);
            $table->string('status', 32)->default('RESERVED');
            $table->dateTime('reserved_at');
            $table->dateTime('expires_at')->nullable();
            $table->dateTime('released_at')->nullable();
            $table->string('release_reason', 255)->nullable();
            $table->unsignedInteger('reserved_by')->nullable();
            $table->timestamps();

            $table->unique(['business_id', 'command_uuid'], 'recommerce_part_reservation_command_unique');
            $table->index(['business_id', 'location_id', 'variation_id', 'status'], 'recommerce_part_reservation_stock_idx');
            $table->index(['business_id', 'repair_job_id', 'status'], 'recommerce_part_reservation_job_idx');
            $table->foreign('business_id')->references('id')->on('business')->onDelete('cascade');
            $table->foreign('location_id')->references('id')->on('business_locations')->onDelete('cascade');
            $table->foreign('repair_job_id')->references('id')->on('recommerce_repair_jobs')->onDelete('restrict');
            $table->foreign('product_id')->references('id')->on('products')->onDelete('restrict');
            $table->foreign('variation_id')->references('id')->on('variations')->onDelete('restrict');
            $table->foreign('reserved_by')->references('id')->on('users')->onDelete('set null');
        });

        Schema::create('recommerce_repair_part_usages', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('business_id');
            $table->unsignedInteger('location_id');
            $table->unsignedBigInteger('repair_job_id');
            $table->unsignedBigInteger('reservation_id');
            $table->unsignedInteger('product_id');
            $table->unsignedInteger('variation_id');
            $table->uuid('usage_uuid');
            $table->uuid('command_uuid');
            $table->string('consumption_path', 16);
            $table->string('status', 32)->default('ISSUED');
            $table->decimal('quantity', 22, 4);
            $table->unsignedInteger('source_transaction_id')->nullable();
            $table->unsignedInteger('source_line_id')->nullable();
            $table->string('source_type', 24)->nullable();
            $table->dateTime('issued_at');
            $table->dateTime('installed_at')->nullable();
            $table->dateTime('resolved_at')->nullable();
            $table->string('resolution_reason', 255)->nullable();
            $table->unsignedInteger('recorded_by')->nullable();
            $table->timestamps();

            $table->unique('usage_uuid');
            $table->unique(['business_id', 'command_uuid'], 'recommerce_part_usage_command_unique');
            $table->unique('reservation_id', 'recommerce_part_usage_reservation_unique');
            $table->index(['business_id', 'repair_job_id', 'status'], 'recommerce_part_usage_job_idx');
            $table->foreign('business_id')->references('id')->on('business')->onDelete('cascade');
            $table->foreign('location_id')->references('id')->on('business_locations')->onDelete('cascade');
            $table->foreign('repair_job_id')->references('id')->on('recommerce_repair_jobs')->onDelete('restrict');
            $table->foreign('reservation_id')->references('id')->on('recommerce_repair_part_reservations')->onDelete('restrict');
            $table->foreign('product_id')->references('id')->on('products')->onDelete('restrict');
            $table->foreign('variation_id')->references('id')->on('variations')->onDelete('restrict');
            $table->foreign('recorded_by')->references('id')->on('users')->onDelete('set null');
        });
    }

    public function down()
    {
        Schema::dropIfExists('recommerce_repair_part_usages');
        Schema::dropIfExists('recommerce_repair_part_reservations');
    }
};
