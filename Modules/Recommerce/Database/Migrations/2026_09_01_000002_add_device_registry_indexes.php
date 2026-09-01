<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        // Existing indexes cover exact identity and stock reconciliation. This
        // covers the Registry's most common branch/state/age query without
        // duplicating either of those index shapes.
        Schema::table('recommerce_devices', function (Blueprint $table) {
            $table->index(
                ['business_id', 'current_location_id', 'lifecycle_state', 'acquired_at'],
                'rc_device_registry_location_state_age_idx'
            );
        });
    }

    public function down()
    {
        Schema::table('recommerce_devices', function (Blueprint $table) {
            $table->dropIndex('rc_device_registry_location_state_age_idx');
        });
    }
};
