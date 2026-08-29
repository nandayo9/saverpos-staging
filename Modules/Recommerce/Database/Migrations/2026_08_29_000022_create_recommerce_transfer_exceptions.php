<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('recommerce_device_transfer_exceptions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('business_id');
            $table->unsignedInteger('sell_transfer_transaction_id');
            $table->unsignedInteger('purchase_transfer_transaction_id');
            $table->unsignedBigInteger('expected_device_id')->nullable();
            $table->unsignedBigInteger('observed_device_id')->nullable();
            $table->string('exception_type', 24);
            $table->string('status', 24)->default('OPEN');
            // Unknown scanned identifiers are never retained in plaintext.
            $table->char('observed_device_code_hash', 64)->nullable();
            $table->string('observed_device_code_hint', 32)->nullable();
            $table->text('evidence_note')->nullable();
            $table->text('resolution_note')->nullable();
            $table->unsignedInteger('recorded_by')->nullable();
            $table->unsignedInteger('resolved_by')->nullable();
            $table->dateTime('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['business_id', 'status'], 'rc_transfer_exception_business_status_idx');
            $table->index(['sell_transfer_transaction_id', 'status'], 'rc_transfer_exception_transfer_status_idx');
            $table->foreign('business_id', 'rc_transfer_exception_business_fk')->references('id')->on('business')->onDelete('cascade');
            $table->foreign('sell_transfer_transaction_id', 'rc_transfer_exception_sell_tx_fk')->references('id')->on('transactions')->onDelete('cascade');
            $table->foreign('purchase_transfer_transaction_id', 'rc_transfer_exception_purchase_tx_fk')->references('id')->on('transactions')->onDelete('cascade');
            $table->foreign('expected_device_id', 'rc_transfer_exception_expected_device_fk')->references('id')->on('recommerce_devices')->onDelete('set null');
            $table->foreign('observed_device_id', 'rc_transfer_exception_observed_device_fk')->references('id')->on('recommerce_devices')->onDelete('set null');
            $table->foreign('recorded_by', 'rc_transfer_exception_recorded_by_fk')->references('id')->on('users')->onDelete('set null');
            $table->foreign('resolved_by', 'rc_transfer_exception_resolved_by_fk')->references('id')->on('users')->onDelete('set null');
        });
    }

    public function down()
    {
        Schema::dropIfExists('recommerce_device_transfer_exceptions');
    }
};
