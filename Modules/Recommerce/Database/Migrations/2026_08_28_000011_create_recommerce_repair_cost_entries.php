<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('recommerce_repair_cost_entries', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('business_id');
            $table->unsignedBigInteger('repair_job_id');
            $table->unsignedBigInteger('device_id')->nullable();
            $table->unsignedBigInteger('part_usage_id')->nullable();
            $table->uuid('cost_uuid');
            $table->string('source_key', 120);
            $table->string('cost_category', 32);
            $table->decimal('amount', 22, 4);
            $table->string('currency', 12)->nullable();
            $table->unsignedInteger('source_transaction_id')->nullable();
            $table->unsignedInteger('source_line_id')->nullable();
            $table->unsignedBigInteger('reversal_of_id')->nullable();
            $table->string('reason', 255)->nullable();
            $table->unsignedInteger('recorded_by')->nullable();
            $table->dateTime('recorded_at');
            $table->timestamps();

            $table->unique('cost_uuid');
            $table->unique(['business_id', 'source_key'], 'recommerce_repair_cost_source_unique');
            $table->index(['business_id', 'device_id', 'cost_category'], 'recommerce_repair_cost_device_idx');
            $table->index(['business_id', 'repair_job_id', 'cost_category'], 'recommerce_repair_cost_job_idx');
            $table->foreign('business_id')->references('id')->on('business')->onDelete('cascade');
            $table->foreign('repair_job_id')->references('id')->on('recommerce_repair_jobs')->onDelete('restrict');
            $table->foreign('device_id')->references('id')->on('recommerce_devices')->onDelete('restrict');
            $table->foreign('part_usage_id')->references('id')->on('recommerce_repair_part_usages')->onDelete('restrict');
            $table->foreign('reversal_of_id')->references('id')->on('recommerce_repair_cost_entries')->onDelete('restrict');
            $table->foreign('recorded_by')->references('id')->on('users')->onDelete('set null');
        });
    }

    public function down()
    {
        Schema::dropIfExists('recommerce_repair_cost_entries');
    }
};
