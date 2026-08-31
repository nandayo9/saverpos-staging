<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('recommerce_devices', function (Blueprint $table) {
            // Movement state is deliberately separate from lifecycle_state so
            // transfer does not turn an inspection/refurbishment state into AVAILABLE.
            $table->string('transfer_state', 32)->default('NONE')->after('stock_participation');
            $table->index(['business_id', 'transfer_state'], 'rc_device_transfer_state_idx');
        });

        Schema::table('recommerce_device_transfer_assignments', function (Blueprint $table) {
            $table->dateTime('dispatched_at')->nullable()->after('transferred_at');
            $table->dateTime('received_at')->nullable()->after('dispatched_at');
            $table->unsignedInteger('received_by')->nullable()->after('received_at');
            $table->string('receipt_condition', 24)->nullable()->after('received_by');
            $table->text('receipt_note')->nullable()->after('receipt_condition');
            $table->index(['sell_transfer_transaction_id', 'status'], 'rc_transfer_assignment_transfer_status_idx');
            $table->foreign('received_by', 'rc_transfer_assign_received_by_fk')->references('id')->on('users')->onDelete('set null');
        });
    }

    public function down()
    {
        Schema::table('recommerce_device_transfer_assignments', function (Blueprint $table) {
            $table->dropForeign('rc_transfer_assign_received_by_fk');
            $table->dropIndex('rc_transfer_assignment_transfer_status_idx');
            $table->dropColumn(['dispatched_at', 'received_at', 'received_by', 'receipt_condition', 'receipt_note']);
        });
        Schema::table('recommerce_devices', function (Blueprint $table) {
            $table->dropIndex('rc_device_transfer_state_idx');
            $table->dropColumn('transfer_state');
        });
    }
};
