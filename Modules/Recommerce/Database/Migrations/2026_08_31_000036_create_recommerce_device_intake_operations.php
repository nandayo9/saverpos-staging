<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        if (! Schema::hasTable('recommerce_device_inspections')) {
        Schema::create('recommerce_device_inspections', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('device_id');
            $table->unsignedInteger('business_id');
            $table->unsignedInteger('location_id');
            $table->unsignedInteger('variation_id');
            $table->unsignedInteger('purchase_transaction_id')->nullable();
            $table->unsignedInteger('purchase_line_id')->nullable();
            $table->string('status', 32)->default('PENDING');
            $table->unsignedInteger('assigned_to')->nullable();
            $table->dateTime('assigned_at')->nullable();
            $table->dateTime('started_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->text('outcome_notes')->nullable();
            $table->dateTime('received_at');
            $table->unsignedInteger('created_by')->nullable();
            $table->unsignedInteger('completed_by')->nullable();
            $table->timestamps();

            $table->unique('device_id', 'recommerce_device_inspection_device_unique');
            $table->index(['business_id', 'location_id', 'status', 'received_at'], 'recommerce_inspection_queue_idx');
            $table->index(['business_id', 'assigned_to', 'status'], 'recommerce_inspection_assignee_idx');
            $table->foreign('device_id', 'rc_inspection_device_fk')->references('id')->on('recommerce_devices')->onDelete('cascade');
            $table->foreign('business_id', 'rc_inspection_business_fk')->references('id')->on('business')->onDelete('cascade');
            $table->foreign('location_id', 'rc_inspection_location_fk')->references('id')->on('business_locations')->onDelete('restrict');
            $table->foreign('assigned_to', 'rc_inspection_assigned_fk')->references('id')->on('users')->onDelete('set null');
            $table->foreign('created_by', 'rc_inspection_created_fk')->references('id')->on('users')->onDelete('set null');
            $table->foreign('completed_by', 'rc_inspection_completed_fk')->references('id')->on('users')->onDelete('set null');
        });
        }

        if (! Schema::hasTable('recommerce_device_intake_observations')) {
        Schema::create('recommerce_device_intake_observations', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('device_id');
            $table->unsignedBigInteger('inspection_id')->nullable();
            $table->unsignedInteger('business_id');
            $table->string('observation_type', 48);
            $table->text('notes')->nullable();
            $table->string('status', 20)->default('OPEN');
            $table->unsignedInteger('recorded_by')->nullable();
            $table->dateTime('recorded_at');
            $table->timestamps();

            $table->index(['business_id', 'device_id', 'status'], 'recommerce_intake_observation_device_idx');
            $table->index(['business_id', 'observation_type', 'status'], 'recommerce_intake_observation_type_idx');
            $table->foreign('device_id', 'rc_intake_observation_device_fk')->references('id')->on('recommerce_devices')->onDelete('cascade');
            $table->foreign('inspection_id', 'rc_intake_observation_inspection_fk')->references('id')->on('recommerce_device_inspections')->onDelete('set null');
            $table->foreign('business_id', 'rc_intake_observation_business_fk')->references('id')->on('business')->onDelete('cascade');
            $table->foreign('recorded_by', 'rc_intake_observation_actor_fk')->references('id')->on('users')->onDelete('set null');
        });
        }

        if (! Schema::hasTable('recommerce_device_cost_override_events')) {
        Schema::create('recommerce_device_cost_override_events', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('device_id');
            $table->unsignedBigInteger('purchase_assignment_id');
            $table->unsignedInteger('business_id');
            $table->decimal('previous_unit_acquisition_cost', 22, 4);
            $table->decimal('new_unit_acquisition_cost', 22, 4);
            $table->string('reason_code', 48);
            $table->text('reason_notes')->nullable();
            $table->unsignedInteger('overridden_by');
            $table->dateTime('overridden_at');
            $table->timestamps();

            $table->index(['business_id', 'device_id', 'overridden_at'], 'recommerce_cost_override_device_idx');
            $table->foreign('device_id', 'rc_cost_override_device_fk')->references('id')->on('recommerce_devices')->onDelete('cascade');
            $table->foreign('purchase_assignment_id', 'rc_cost_override_assignment_fk')->references('id')->on('recommerce_device_purchase_assignments')->onDelete('cascade');
            $table->foreign('business_id', 'rc_cost_override_business_fk')->references('id')->on('business')->onDelete('cascade');
            $table->foreign('overridden_by', 'rc_cost_override_actor_fk')->references('id')->on('users')->onDelete('restrict');
        });
        }

        // Pending Devices created before this operational table existed keep
        // their lifecycle state; this backfill only makes them visible in the
        // new queue. It creates no stock, accounting, or movement rows.
        DB::table('recommerce_devices as d')
            ->leftJoin('recommerce_device_purchase_assignments as dpa', 'dpa.device_id', '=', 'd.id')
            ->where('d.lifecycle_state', 'RECEIVED_PENDING_INSPECTION')
            ->orderBy('d.id')
            ->select([
                'd.id as device_id', 'd.business_id', 'd.current_location_id as location_id', 'd.variation_id',
                'd.acquired_at', 'd.created_at', 'd.created_by', 'dpa.transaction_id as purchase_transaction_id',
                'dpa.purchase_line_id',
            ])
            ->chunkById(200, function ($devices) {
                foreach ($devices as $device) {
                    if (! $device->location_id || ! $device->variation_id) {
                        continue;
                    }
                    DB::table('recommerce_device_inspections')->updateOrInsert(
                        ['device_id' => $device->device_id],
                        [
                            'business_id' => $device->business_id,
                            'location_id' => $device->location_id,
                            'variation_id' => $device->variation_id,
                            'purchase_transaction_id' => $device->purchase_transaction_id,
                            'purchase_line_id' => $device->purchase_line_id,
                            'status' => 'PENDING',
                            'received_at' => $device->acquired_at ?: ($device->created_at ?: now()),
                            'created_by' => $device->created_by,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]
                    );
                }
            }, 'd.id', 'device_id');
    }

    public function down()
    {
        Schema::dropIfExists('recommerce_device_cost_override_events');
        Schema::dropIfExists('recommerce_device_intake_observations');
        Schema::dropIfExists('recommerce_device_inspections');
    }
};
