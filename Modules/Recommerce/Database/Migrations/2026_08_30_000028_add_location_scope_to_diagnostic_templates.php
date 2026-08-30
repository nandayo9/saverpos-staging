<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        if (Schema::hasColumn('recommerce_diagnostic_templates', 'location_id')) {
            return;
        }

        Schema::table('recommerce_diagnostic_templates', function (Blueprint $table): void {
            $table->unsignedInteger('location_id')->nullable()->after('business_id');
            $table->index(['business_id', 'location_id'], 'recommerce_diagnostic_template_location_idx');
            $table->foreign('location_id')->references('id')->on('business_locations')->onDelete('set null');
        });
    }

    public function down()
    {
        if (! Schema::hasColumn('recommerce_diagnostic_templates', 'location_id')) {
            return;
        }

        Schema::table('recommerce_diagnostic_templates', function (Blueprint $table): void {
            $table->dropForeign(['location_id']);
            $table->dropIndex('recommerce_diagnostic_template_location_idx');
            $table->dropColumn('location_id');
        });
    }
};
