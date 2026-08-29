<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('recommerce_serialization_profiles', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('business_id');
            $table->unsignedInteger('product_id');
            $table->unsignedInteger('variation_id');
            $table->string('mode', 24);
            $table->unsignedInteger('version')->default(1);
            $table->dateTime('effective_at');
            $table->unsignedInteger('configured_by')->nullable();
            $table->string('approval_reference', 160)->nullable();
            $table->timestamps();

            $table->unique(['business_id', 'variation_id'], 'rc_ser_profile_business_variation_uniq');
            $table->index(['business_id', 'product_id'], 'rc_ser_profile_business_product_idx');
            $table->foreign('business_id', 'rc_ser_profile_business_fk')->references('id')->on('business')->onDelete('cascade');
            $table->foreign('product_id', 'rc_ser_profile_product_fk')->references('id')->on('products')->onDelete('cascade');
            $table->foreign('variation_id', 'rc_ser_profile_variation_fk')->references('id')->on('variations')->onDelete('cascade');
            $table->foreign('configured_by', 'rc_ser_profile_configured_by_fk')->references('id')->on('users')->onDelete('set null');
        });

        Schema::create('recommerce_legacy_stock_balances', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('serialization_profile_id');
            $table->unsignedInteger('business_id');
            $table->unsignedInteger('location_id');
            $table->unsignedInteger('variation_id');
            $table->decimal('legacy_unserialized_qty', 22, 4);
            $table->dateTime('approved_at');
            $table->unsignedInteger('approved_by')->nullable();
            $table->string('evidence_reference', 160)->nullable();
            $table->string('reason', 255)->nullable();
            $table->timestamps();

            $table->unique(['serialization_profile_id', 'location_id'], 'recommerce_legacy_balance_scope_unique');
            $table->index(['business_id', 'location_id', 'variation_id'], 'rc_legacy_balance_lookup_idx');
            $table->foreign('serialization_profile_id', 'rc_legacy_balance_profile_fk')->references('id')->on('recommerce_serialization_profiles')->onDelete('cascade');
            $table->foreign('business_id', 'rc_legacy_balance_business_fk')->references('id')->on('business')->onDelete('cascade');
            $table->foreign('location_id', 'rc_legacy_balance_location_fk')->references('id')->on('business_locations')->onDelete('cascade');
            $table->foreign('variation_id', 'rc_legacy_balance_variation_fk')->references('id')->on('variations')->onDelete('cascade');
            $table->foreign('approved_by', 'rc_legacy_balance_approved_by_fk')->references('id')->on('users')->onDelete('set null');
        });

        Schema::create('recommerce_stock_commands', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('business_id');
            $table->uuid('command_uuid');
            $table->string('command_type', 80);
            $table->string('request_hash', 64);
            $table->unsignedInteger('actor_id')->nullable();
            $table->string('status', 32);
            $table->json('result_json')->nullable();
            $table->dateTime('started_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['business_id', 'command_uuid'], 'rc_stock_command_business_uuid_uniq');
            $table->index(['business_id', 'status'], 'rc_stock_command_status_idx');
            $table->foreign('business_id', 'rc_stock_command_business_fk')->references('id')->on('business')->onDelete('cascade');
            $table->foreign('actor_id', 'rc_stock_command_actor_fk')->references('id')->on('users')->onDelete('set null');
        });

        Schema::create('recommerce_devices', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('business_id');
            $table->uuid('device_uuid');
            $table->string('device_code', 64);
            $table->string('category_code', 32)->nullable();
            $table->string('ownership_kind', 20);
            $table->unsignedInteger('current_owner_contact_id')->nullable();
            $table->string('custody_kind', 20);
            $table->unsignedInteger('current_location_id')->nullable();
            $table->unsignedInteger('product_id')->nullable();
            $table->unsignedInteger('variation_id')->nullable();
            $table->string('lifecycle_state', 32);
            $table->string('stock_participation', 20);
            $table->json('specifications_json')->nullable();
            $table->string('manufacturer_serial_display', 160)->nullable();
            $table->dateTime('acquired_at')->nullable();
            $table->dateTime('sold_at')->nullable();
            $table->dateTime('retired_at')->nullable();
            $table->unsignedInteger('lock_version')->default(1);
            $table->unsignedInteger('created_by')->nullable();
            $table->unsignedInteger('updated_by')->nullable();
            $table->timestamps();

            $table->unique('device_uuid', 'rc_device_uuid_uniq');
            $table->unique(['business_id', 'device_code'], 'rc_device_business_code_uniq');
            $table->index(['business_id', 'variation_id', 'current_location_id', 'stock_participation'], 'recommerce_device_stock_idx');
            $table->index(['business_id', 'lifecycle_state'], 'rc_device_lifecycle_idx');
            $table->foreign('business_id', 'rc_device_business_fk')->references('id')->on('business')->onDelete('cascade');
            $table->foreign('current_owner_contact_id', 'rc_device_owner_contact_fk')->references('id')->on('contacts')->onDelete('set null');
            $table->foreign('current_location_id', 'rc_device_location_fk')->references('id')->on('business_locations')->onDelete('set null');
            $table->foreign('product_id', 'rc_device_product_fk')->references('id')->on('products')->onDelete('set null');
            $table->foreign('variation_id', 'rc_device_variation_fk')->references('id')->on('variations')->onDelete('set null');
            $table->foreign('created_by', 'rc_device_created_by_fk')->references('id')->on('users')->onDelete('set null');
            $table->foreign('updated_by', 'rc_device_updated_by_fk')->references('id')->on('users')->onDelete('set null');
        });

        Schema::create('recommerce_device_identifiers', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('device_id');
            $table->unsignedInteger('business_id');
            $table->string('identifier_type', 40);
            $table->text('raw_value_encrypted')->nullable();
            // Every identifier is looked up by its keyed hash; nullable values
            // would allow duplicate rows through SQL unique constraints.
            $table->string('normalized_hash', 64);
            $table->string('issuer', 80)->nullable();
            $table->boolean('is_verified')->default(false);
            $table->dateTime('verified_at')->nullable();
            $table->unsignedInteger('verified_by')->nullable();
            $table->timestamps();

            $table->unique(['business_id', 'identifier_type', 'normalized_hash'], 'recommerce_identifier_unique');
            $table->index(['business_id', 'device_id'], 'rc_identifier_business_device_idx');
            $table->foreign('device_id', 'rc_identifier_device_fk')->references('id')->on('recommerce_devices')->onDelete('cascade');
            $table->foreign('business_id', 'rc_identifier_business_fk')->references('id')->on('business')->onDelete('cascade');
            $table->foreign('verified_by', 'rc_identifier_verified_by_fk')->references('id')->on('users')->onDelete('set null');
        });

        Schema::create('recommerce_scan_tokens', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('business_id');
            $table->string('subject_type', 20);
            $table->unsignedBigInteger('device_id');
            $table->string('token_hash', 64);
            $table->string('token_hint', 16)->nullable();
            $table->string('status', 20);
            $table->dateTime('issued_at');
            $table->dateTime('revoked_at')->nullable();
            $table->unsignedBigInteger('replaced_by_id')->nullable();
            $table->unsignedInteger('issued_by')->nullable();
            $table->string('reason', 160)->nullable();
            $table->timestamps();

            $table->unique('token_hash', 'rc_scan_token_hash_uniq');
            $table->index(['device_id', 'subject_type', 'status'], 'recommerce_token_status_idx');
            $table->index(['business_id', 'device_id', 'status'], 'rc_scan_token_business_device_idx');
            $table->foreign('business_id', 'rc_scan_token_business_fk')->references('id')->on('business')->onDelete('cascade');
            $table->foreign('device_id', 'rc_scan_token_device_fk')->references('id')->on('recommerce_devices')->onDelete('cascade');
            $table->foreign('replaced_by_id', 'rc_scan_token_replaced_by_fk')->references('id')->on('recommerce_scan_tokens')->onDelete('set null');
            $table->foreign('issued_by', 'rc_scan_token_issued_by_fk')->references('id')->on('users')->onDelete('set null');
        });

        Schema::create('recommerce_device_purchase_assignments', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('device_id');
            $table->unsignedInteger('business_id');
            $table->unsignedInteger('transaction_id');
            $table->unsignedInteger('purchase_line_id');
            $table->unsignedInteger('unit_ordinal');
            $table->decimal('unit_acquisition_cost', 22, 4)->nullable();
            $table->decimal('landed_allocation', 22, 4)->nullable();
            $table->dateTime('assigned_at');
            $table->unsignedInteger('assigned_by')->nullable();
            $table->timestamps();

            $table->unique('device_id', 'rc_purchase_assignment_device_uniq');
            $table->unique(['purchase_line_id', 'unit_ordinal'], 'recommerce_purchase_unit_unique');
            $table->index(['business_id', 'transaction_id'], 'rc_purchase_assignment_transaction_idx');
            $table->foreign('device_id', 'rc_purchase_assignment_device_fk')->references('id')->on('recommerce_devices')->onDelete('cascade');
            $table->foreign('business_id', 'rc_purchase_assignment_business_fk')->references('id')->on('business')->onDelete('cascade');
            $table->foreign('transaction_id', 'rc_purchase_assignment_transaction_fk')->references('id')->on('transactions')->onDelete('cascade');
            $table->foreign('purchase_line_id', 'rc_purchase_assignment_line_fk')->references('id')->on('purchase_lines')->onDelete('cascade');
            $table->foreign('assigned_by', 'rc_purchase_assignment_assigned_by_fk')->references('id')->on('users')->onDelete('set null');
        });

        Schema::create('recommerce_device_movements', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('device_id');
            $table->unsignedInteger('business_id');
            $table->string('movement_type', 40);
            $table->string('from_custody_kind', 20)->nullable();
            $table->unsignedInteger('from_location_id')->nullable();
            $table->string('to_custody_kind', 20)->nullable();
            $table->unsignedInteger('to_location_id')->nullable();
            $table->unsignedInteger('source_transaction_id')->nullable();
            $table->unsignedInteger('source_line_id')->nullable();
            $table->string('source_line_type', 40)->nullable();
            $table->uuid('command_uuid')->nullable();
            $table->dateTime('occurred_at');
            $table->unsignedInteger('recorded_by')->nullable();
            $table->unsignedBigInteger('reversal_of_id')->nullable();
            $table->string('reason', 255)->nullable();
            $table->timestamps();

            $table->unique(['business_id', 'device_id', 'command_uuid'], 'recommerce_movement_command_unique');
            $table->index(['business_id', 'device_id', 'occurred_at'], 'rc_movement_timeline_idx');
            $table->foreign('device_id', 'rc_movement_device_fk')->references('id')->on('recommerce_devices')->onDelete('cascade');
            $table->foreign('business_id', 'rc_movement_business_fk')->references('id')->on('business')->onDelete('cascade');
            $table->foreign('from_location_id', 'rc_movement_from_location_fk')->references('id')->on('business_locations')->onDelete('set null');
            $table->foreign('to_location_id', 'rc_movement_to_location_fk')->references('id')->on('business_locations')->onDelete('set null');
            $table->foreign('recorded_by', 'rc_movement_recorded_by_fk')->references('id')->on('users')->onDelete('set null');
            $table->foreign('reversal_of_id', 'rc_movement_reversal_fk')->references('id')->on('recommerce_device_movements')->onDelete('set null');
        });

        Schema::create('recommerce_device_events', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('device_id');
            $table->unsignedInteger('business_id');
            $table->unsignedInteger('actor_id')->nullable();
            $table->string('event_type', 40);
            $table->uuid('source_command_uuid')->nullable();
            $table->unsignedInteger('source_transaction_id')->nullable();
            $table->json('metadata_json')->nullable();
            $table->dateTime('occurred_at');
            $table->timestamps();

            $table->index(['business_id', 'device_id', 'occurred_at'], 'recommerce_device_event_timeline_idx');
            $table->index(['business_id', 'source_command_uuid'], 'recommerce_device_event_command_idx');
            $table->foreign('device_id', 'rc_device_event_device_fk')->references('id')->on('recommerce_devices')->onDelete('cascade');
            $table->foreign('business_id', 'rc_device_event_business_fk')->references('id')->on('business')->onDelete('cascade');
            $table->foreign('actor_id', 'rc_device_event_actor_fk')->references('id')->on('users')->onDelete('set null');
        });

        Schema::create('recommerce_outbox_messages', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('event_id');
            $table->unsignedInteger('business_id');
            $table->string('topic', 80);
            $table->json('payload_json');
            $table->string('status', 20)->default('PENDING');
            $table->unsignedInteger('attempts')->default(0);
            $table->dateTime('available_at')->nullable();
            $table->dateTime('processed_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->unique('event_id', 'recommerce_outbox_event_unique');
            $table->index(['business_id', 'status', 'available_at'], 'recommerce_outbox_delivery_idx');
            $table->foreign('event_id', 'rc_outbox_event_fk')->references('id')->on('recommerce_device_events')->onDelete('cascade');
            $table->foreign('business_id', 'rc_outbox_business_fk')->references('id')->on('business')->onDelete('cascade');
        });
    }

    public function down()
    {
        Schema::dropIfExists('recommerce_outbox_messages');
        Schema::dropIfExists('recommerce_device_events');
        Schema::dropIfExists('recommerce_device_movements');
        Schema::dropIfExists('recommerce_device_purchase_assignments');
        Schema::dropIfExists('recommerce_scan_tokens');
        Schema::dropIfExists('recommerce_device_identifiers');
        Schema::dropIfExists('recommerce_devices');
        Schema::dropIfExists('recommerce_stock_commands');
        Schema::dropIfExists('recommerce_legacy_stock_balances');
        Schema::dropIfExists('recommerce_serialization_profiles');
    }
};
