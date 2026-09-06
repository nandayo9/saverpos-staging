<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('recommerce_trade_in_catalogue_origins', function (Blueprint $table) {
            $table->string('duplicate_override_reason', 64)->nullable()->after('created_by');
            $table->string('duplicate_override_note', 500)->nullable()->after('duplicate_override_reason');
        });
    }

    public function down()
    {
        Schema::table('recommerce_trade_in_catalogue_origins', function (Blueprint $table) {
            $table->dropColumn(['duplicate_override_reason', 'duplicate_override_note']);
        });
    }
};
