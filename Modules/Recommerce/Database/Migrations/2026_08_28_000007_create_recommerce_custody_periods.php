<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('recommerce_device_custody_periods', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('device_id');
            $table->unsignedInteger('business_id');
            $table->string('custody_kind', 20);
            $table->unsignedInteger('location_id')->nullable();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at')->nullable();
            // NULL for closed history; the device ID for the one open period.
            $table->unsignedBigInteger('open_period_key')->nullable();
            $table->unsignedBigInteger('source_movement_id')->nullable();
            $table->string('reason', 40);
            $table->unsignedInteger('recorded_by')->nullable();
            $table->timestamps();

            $table->unique('open_period_key', 'recommerce_custody_open_unique');
            $table->index(['business_id', 'device_id', 'starts_at'], 'recommerce_custody_timeline_idx');
            $table->index(['business_id', 'location_id', 'custody_kind'], 'rc_custody_location_lookup_idx');
            $table->foreign('device_id')->references('id')->on('recommerce_devices')->onDelete('cascade');
            $table->foreign('business_id')->references('id')->on('business')->onDelete('cascade');
            $table->foreign('location_id')->references('id')->on('business_locations')->onDelete('set null');
            $table->foreign('source_movement_id')->references('id')->on('recommerce_device_movements')->onDelete('set null');
            $table->foreign('recorded_by')->references('id')->on('users')->onDelete('set null');
        });
    }

    public function down()
    {
        Schema::dropIfExists('recommerce_device_custody_periods');
    }
};
