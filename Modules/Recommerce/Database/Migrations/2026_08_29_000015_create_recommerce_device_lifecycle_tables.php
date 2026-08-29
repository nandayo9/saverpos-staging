<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('recommerce_device_sale_dispositions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('device_id');
            $table->unsignedInteger('business_id');
            $table->unsignedInteger('sale_transaction_id');
            $table->unsignedInteger('sell_line_id');
            $table->unsignedInteger('customer_contact_id')->nullable();
            $table->dateTime('sold_at');
            $table->dateTime('reversed_at')->nullable();
            $table->unsignedInteger('reversal_transaction_id')->nullable();
            // Device ID while this is the current sale; NULL once superseded.
            $table->unsignedBigInteger('active_sale_key')->nullable();
            $table->uuid('command_uuid')->nullable();
            $table->unsignedInteger('recorded_by')->nullable();
            $table->string('reason', 160)->nullable();
            $table->timestamps();

            // A void and later re-sale may legitimately reference the same
            // historical sale line/device pair. active_sale_key is the
            // enforced current-state invariant; this index serves lookup.
            $table->index(['sale_transaction_id', 'sell_line_id', 'device_id'], 'rc_sale_disposition_line_device_idx');
            $table->unique('active_sale_key', 'rc_sale_disposition_active_device_unique');
            $table->index(['business_id', 'sale_transaction_id'], 'rc_sale_disposition_transaction_idx');
            $table->foreign('device_id', 'rc_sale_disp_device_fk')->references('id')->on('recommerce_devices')->onDelete('cascade');
            $table->foreign('business_id', 'rc_sale_disp_business_fk')->references('id')->on('business')->onDelete('cascade');
            $table->foreign('sale_transaction_id', 'rc_sale_disp_sale_tx_fk')->references('id')->on('transactions')->onDelete('cascade');
            $table->foreign('sell_line_id', 'rc_sale_disp_line_fk')->references('id')->on('transaction_sell_lines')->onDelete('cascade');
            $table->foreign('customer_contact_id', 'rc_sale_disp_contact_fk')->references('id')->on('contacts')->onDelete('set null');
            $table->foreign('reversal_transaction_id', 'rc_sale_disp_reversal_tx_fk')->references('id')->on('transactions')->onDelete('set null');
            $table->foreign('recorded_by', 'rc_sale_disp_user_fk')->references('id')->on('users')->onDelete('set null');
        });

        Schema::create('recommerce_device_transfer_assignments', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('device_id');
            $table->unsignedInteger('business_id');
            $table->unsignedInteger('sell_transfer_transaction_id');
            $table->unsignedInteger('purchase_transfer_transaction_id');
            $table->unsignedInteger('sell_line_id');
            $table->unsignedInteger('from_location_id');
            $table->unsignedInteger('to_location_id');
            $table->dateTime('transferred_at');
            $table->dateTime('reversed_at')->nullable();
            $table->unsignedInteger('reversal_transaction_id')->nullable();
            $table->unsignedBigInteger('active_transfer_key')->nullable();
            $table->uuid('command_uuid')->nullable();
            $table->unsignedInteger('recorded_by')->nullable();
            $table->timestamps();

            $table->unique(['sell_transfer_transaction_id', 'sell_line_id', 'device_id'], 'rc_transfer_assignment_line_device_unique');
            $table->unique('active_transfer_key', 'rc_transfer_assignment_active_device_unique');
            $table->index(['business_id', 'sell_transfer_transaction_id'], 'rc_transfer_assignment_transaction_idx');
            $table->foreign('device_id', 'rc_transfer_assign_device_fk')->references('id')->on('recommerce_devices')->onDelete('cascade');
            $table->foreign('business_id', 'rc_transfer_assign_business_fk')->references('id')->on('business')->onDelete('cascade');
            $table->foreign('sell_transfer_transaction_id', 'rc_transfer_assign_sell_tx_fk')->references('id')->on('transactions')->onDelete('cascade');
            $table->foreign('purchase_transfer_transaction_id', 'rc_transfer_assign_purchase_tx_fk')->references('id')->on('transactions')->onDelete('cascade');
            $table->foreign('sell_line_id', 'rc_transfer_assign_line_fk')->references('id')->on('transaction_sell_lines')->onDelete('cascade');
            $table->foreign('from_location_id', 'rc_transfer_assign_from_loc_fk')->references('id')->on('business_locations')->onDelete('cascade');
            $table->foreign('to_location_id', 'rc_transfer_assign_to_loc_fk')->references('id')->on('business_locations')->onDelete('cascade');
            $table->foreign('reversal_transaction_id', 'rc_transfer_assign_reversal_fk')->references('id')->on('transactions')->onDelete('set null');
            $table->foreign('recorded_by', 'rc_transfer_assign_user_fk')->references('id')->on('users')->onDelete('set null');
        });

        Schema::create('recommerce_device_return_dispositions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('device_id');
            $table->unsignedBigInteger('sale_disposition_id');
            $table->unsignedInteger('business_id');
            $table->unsignedInteger('return_transaction_id');
            $table->unsignedInteger('return_line_id');
            $table->string('resulting_lifecycle_state', 40);
            $table->dateTime('returned_at');
            $table->dateTime('reversed_at')->nullable();
            $table->unsignedBigInteger('active_return_key')->nullable();
            $table->uuid('command_uuid')->nullable();
            $table->unsignedInteger('recorded_by')->nullable();
            $table->timestamps();

            $table->unique(['return_transaction_id', 'return_line_id', 'device_id'], 'rc_return_disposition_line_device_unique');
            $table->unique('active_return_key', 'rc_return_disposition_active_device_unique');
            $table->index(['business_id', 'return_transaction_id'], 'rc_return_disposition_transaction_idx');
            $table->foreign('device_id', 'rc_return_disp_device_fk')->references('id')->on('recommerce_devices')->onDelete('cascade');
            $table->foreign('sale_disposition_id', 'rc_return_disp_sale_fk')->references('id')->on('recommerce_device_sale_dispositions')->onDelete('cascade');
            $table->foreign('business_id', 'rc_return_disp_business_fk')->references('id')->on('business')->onDelete('cascade');
            $table->foreign('return_transaction_id', 'rc_return_disp_tx_fk')->references('id')->on('transactions')->onDelete('cascade');
            $table->foreign('return_line_id', 'rc_return_disp_line_fk')->references('id')->on('transaction_sell_lines')->onDelete('cascade');
            $table->foreign('recorded_by', 'rc_return_disp_user_fk')->references('id')->on('users')->onDelete('set null');
        });
    }

    public function down()
    {
        Schema::dropIfExists('recommerce_device_return_dispositions');
        Schema::dropIfExists('recommerce_device_transfer_assignments');
        Schema::dropIfExists('recommerce_device_sale_dispositions');
    }
};
