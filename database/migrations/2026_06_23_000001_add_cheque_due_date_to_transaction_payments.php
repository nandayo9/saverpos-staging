<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cheque due date is part of the core POS so the payment screens keep
     * working even when the Cheque module is not installed. The Cheque
     * module builds its extra features (status, assignee, cleared date)
     * on top of this column.
     */
    public function up()
    {
        Schema::table('transaction_payments', function (Blueprint $table) {
            $table->date('cheque_due_date')->nullable()->after('cheque_number');
        });
    }

    public function down()
    {
        Schema::table('transaction_payments', function (Blueprint $table) {
            $table->dropColumn('cheque_due_date');
        });
    }
};
