<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('recommerce_device_events', function (Blueprint $table) {
            // Nullable preserves compatibility with any Alpha rows created
            // before this hardening migration; all new recorder events set it.
            $table->uuid('event_uuid')->nullable();
            $table->unsignedInteger('event_version')->default(1);
        });

        Schema::table('recommerce_device_events', function (Blueprint $table) {
            $table->unique('event_uuid', 'recommerce_device_event_uuid_unique');
        });
    }

    public function down()
    {
        Schema::table('recommerce_device_events', function (Blueprint $table) {
            $table->dropUnique('recommerce_device_event_uuid_unique');
            $table->dropColumn(['event_uuid', 'event_version']);
        });
    }
};
