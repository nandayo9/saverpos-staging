<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('recommerce_stock_count_sessions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('session_uuid');
            $table->unsignedInteger('business_id');
            $table->unsignedInteger('location_id');
            $table->string('count_type', 24);
            $table->string('status', 32)->default('DRAFT');
            $table->json('scope_json')->nullable();
            $table->boolean('blind_count')->default(false);
            $table->dateTime('snapshot_at')->nullable();
            $table->string('snapshot_hash', 64)->nullable();
            $table->unsignedInteger('created_by')->nullable();
            $table->unsignedInteger('started_by')->nullable();
            $table->dateTime('started_at')->nullable();
            $table->unsignedInteger('approved_by')->nullable();
            $table->dateTime('approved_at')->nullable();
            $table->unsignedInteger('reconciled_by')->nullable();
            $table->dateTime('reconciled_at')->nullable();
            $table->unsignedInteger('closed_by')->nullable();
            $table->dateTime('closed_at')->nullable();
            $table->timestamps();

            $table->unique(['business_id', 'session_uuid'], 'rc_stock_count_session_uuid_unique');
            $table->index(['business_id', 'location_id', 'status'], 'rc_stock_count_session_scope_idx');
            $table->foreign('business_id')->references('id')->on('business')->onDelete('cascade');
            $table->foreign('location_id')->references('id')->on('business_locations')->onDelete('cascade');
        });

        Schema::create('recommerce_stock_count_items', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('session_id');
            $table->string('item_kind', 24); // SERIALIZED_DEVICE or NON_SERIALIZED_VARIATION
            $table->unsignedBigInteger('device_id')->nullable();
            $table->unsignedInteger('product_id')->nullable();
            $table->unsignedInteger('variation_id')->nullable();
            $table->decimal('expected_quantity', 22, 4);
            $table->decimal('counted_quantity', 22, 4)->default(0);
            $table->decimal('reconciled_quantity', 22, 4)->nullable();
            $table->json('snapshot_json');
            $table->dateTime('counted_at')->nullable();
            $table->unsignedInteger('counted_by')->nullable();
            $table->timestamps();

            $table->unique(['session_id', 'device_id'], 'rc_stock_count_item_device_unique');
            // Device rows intentionally share a variation. The one generic
            // row per variation is enforced by the creation transaction.
            $table->index(['session_id', 'item_kind', 'variation_id'], 'rc_stock_count_item_session_kind_idx');
            $table->foreign('session_id')->references('id')->on('recommerce_stock_count_sessions')->onDelete('cascade');
        });

        Schema::create('recommerce_stock_count_entries', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('session_id');
            $table->unsignedBigInteger('item_id')->nullable();
            $table->unsignedBigInteger('device_id')->nullable();
            $table->string('entry_type', 24); // SCAN or QUANTITY
            $table->string('result_type', 32);
            $table->decimal('quantity', 22, 4)->nullable();
            $table->string('input_hash', 64)->nullable(); // Never retain a typed protected identifier.
            $table->unsignedInteger('recorded_by')->nullable();
            $table->dateTime('recorded_at');
            $table->timestamps();

            $table->unique(['session_id', 'device_id'], 'rc_stock_count_entry_device_unique');
            $table->index(['session_id', 'result_type'], 'rc_stock_count_entry_session_result_idx');
            $table->foreign('session_id')->references('id')->on('recommerce_stock_count_sessions')->onDelete('cascade');
        });

        Schema::create('recommerce_stock_count_exceptions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('session_id');
            $table->unsignedBigInteger('item_id')->nullable();
            $table->unsignedBigInteger('device_id')->nullable();
            $table->string('exception_type', 48);
            $table->string('severity', 16)->default('REVIEW');
            $table->string('status', 24)->default('OPEN');
            $table->json('context_json')->nullable();
            $table->string('resolution_code', 48)->nullable();
            $table->text('resolution_note')->nullable();
            $table->unsignedInteger('resolved_by')->nullable();
            $table->dateTime('resolved_at')->nullable();
            $table->timestamps();

            $table->unique(['session_id', 'device_id', 'exception_type'], 'rc_stock_count_exception_device_unique');
            $table->unique(['session_id', 'item_id', 'exception_type'], 'rc_stock_count_exception_item_unique');
            $table->index(['session_id', 'status', 'severity'], 'rc_stock_count_exception_session_idx');
            $table->foreign('session_id')->references('id')->on('recommerce_stock_count_sessions')->onDelete('cascade');
        });

        Schema::create('recommerce_stock_count_audits', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('session_id');
            $table->unsignedInteger('actor_id')->nullable();
            $table->string('event_type', 48);
            $table->json('metadata_json')->nullable();
            $table->dateTime('occurred_at');
            $table->timestamps();
            $table->index(['session_id', 'occurred_at'], 'rc_stock_count_audit_session_idx');
            $table->foreign('session_id')->references('id')->on('recommerce_stock_count_sessions')->onDelete('cascade');
        });
    }

    public function down()
    {
        Schema::dropIfExists('recommerce_stock_count_audits');
        Schema::dropIfExists('recommerce_stock_count_exceptions');
        Schema::dropIfExists('recommerce_stock_count_entries');
        Schema::dropIfExists('recommerce_stock_count_items');
        Schema::dropIfExists('recommerce_stock_count_sessions');
    }
};
