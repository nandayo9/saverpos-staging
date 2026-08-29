<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('recommerce_device_certifications', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('device_id');
            $table->unsignedInteger('business_id');
            $table->string('grade', 2);
            $table->boolean('qc_passed');
            $table->unsignedTinyInteger('battery_health_percent');
            $table->dateTime('purchased_at');
            $table->dateTime('warranty_expires_at');
            $table->string('status', 20)->default('ACTIVE');
            $table->dateTime('published_at');
            $table->unsignedInteger('published_by')->nullable();
            $table->timestamps();

            $table->unique('device_id', 'rc_device_certification_device_unique');
            $table->index(['business_id', 'status'], 'rc_device_certification_public_lookup_idx');
            $table->foreign('device_id', 'rc_device_certification_device_fk')->references('id')->on('recommerce_devices')->onDelete('cascade');
            $table->foreign('business_id', 'rc_device_certification_business_fk')->references('id')->on('business')->onDelete('cascade');
            $table->foreign('published_by', 'rc_device_certification_published_by_fk')->references('id')->on('users')->onDelete('set null');
        });
    }

    public function down()
    {
        Schema::dropIfExists('recommerce_device_certifications');
    }
};
