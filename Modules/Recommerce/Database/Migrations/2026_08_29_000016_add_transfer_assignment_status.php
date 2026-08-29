<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('recommerce_device_transfer_assignments', function (Blueprint $table) {
            $table->string('status', 24)->default('RESERVED')->after('transferred_at');
            $table->index(['business_id', 'status'], 'rc_transfer_assignment_status_idx');
        });
    }

    public function down()
    {
        Schema::table('recommerce_device_transfer_assignments', function (Blueprint $table) {
            $table->dropIndex('rc_transfer_assignment_status_idx');
            $table->dropColumn('status');
        });
    }
};
