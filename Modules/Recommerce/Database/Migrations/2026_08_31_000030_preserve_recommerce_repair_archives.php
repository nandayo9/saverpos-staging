<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        if (! Schema::hasTable('recommerce_repair_archives')) {
            return;
        }

        Schema::table('recommerce_repair_archives', function (Blueprint $table) {
            $table->dropForeign('recommerce_repair_archives_transaction_id_foreign');
            $table->foreign('transaction_id')
                ->references('id')
                ->on('transactions')
                ->onDelete('set null');
        });
    }

    public function down()
    {
        if (! Schema::hasTable('recommerce_repair_archives')) {
            return;
        }

        Schema::table('recommerce_repair_archives', function (Blueprint $table) {
            $table->dropForeign('recommerce_repair_archives_transaction_id_foreign');
            $table->foreign('transaction_id')
                ->references('id')
                ->on('transactions')
                ->onDelete('cascade');
        });
    }
};
