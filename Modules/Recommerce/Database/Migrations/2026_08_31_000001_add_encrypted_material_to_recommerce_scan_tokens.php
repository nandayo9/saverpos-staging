<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('recommerce_scan_tokens', function (Blueprint $table) {
            // Never plaintext: permits an exact QR reprint for newly issued
            // labels without exposing the opaque bearer value in queries.
            $table->text('raw_token_encrypted')->nullable()->after('token_hash');
        });
    }

    public function down()
    {
        Schema::table('recommerce_scan_tokens', function (Blueprint $table) {
            $table->dropColumn('raw_token_encrypted');
        });
    }
};
