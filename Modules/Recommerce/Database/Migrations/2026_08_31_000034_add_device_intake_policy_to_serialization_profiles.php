<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('recommerce_serialization_profiles', function (Blueprint $table) {
            // Existing approved TRACKED_REQUIRED profiles remain serialized.
            // Explicit BULK rows are supported for a future product-policy UI
            // without adding a second inventory configuration table.
            $table->string('inventory_tracking_mode', 32)->default('SERIALIZED_DEVICE');
            $table->boolean('inspection_required')->default(true);
        });
    }

    public function down()
    {
        Schema::table('recommerce_serialization_profiles', function (Blueprint $table) {
            $table->dropColumn(['inventory_tracking_mode', 'inspection_required']);
        });
    }
};
