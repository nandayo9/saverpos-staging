<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('recommerce_trade_in_outbox_messages', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('event_uuid');
            $table->unsignedBigInteger('intake_id');
            $table->unsignedInteger('business_id');
            $table->unsignedInteger('aggregate_version');
            $table->string('event_type', 64);
            $table->string('schema_version', 24)->default('1.0');
            $table->json('payload_json');
            $table->string('destination', 24)->default('SAVERBRO_WEBSITE');
            $table->string('status', 20)->default('PENDING');
            $table->unsignedInteger('attempt_count')->default(0);
            $table->dateTime('available_at')->nullable();
            $table->dateTime('last_attempt_at')->nullable();
            $table->dateTime('delivered_at')->nullable();
            $table->string('last_error_code', 40)->nullable();
            $table->string('last_error', 255)->nullable();
            $table->timestamps();

            $table->unique('event_uuid', 'rc_ti_outbox_event_uuid_unique');
            $table->unique(['intake_id', 'aggregate_version'], 'rc_ti_outbox_intake_version_unique');
            $table->index(['status', 'available_at'], 'rc_ti_outbox_delivery_idx');
            $table->foreign('intake_id')->references('id')->on('recommerce_trade_in_intakes')->onDelete('restrict');
            $table->foreign('business_id')->references('id')->on('business')->onDelete('cascade');
        });
    }

    public function down()
    {
        Schema::dropIfExists('recommerce_trade_in_outbox_messages');
    }
};
