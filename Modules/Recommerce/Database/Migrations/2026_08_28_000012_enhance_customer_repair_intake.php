<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('recommerce_repair_jobs', function (Blueprint $table) {
            $table->string('command_hash', 64)->nullable();
            $table->text('reported_fault')->nullable();
            $table->text('cosmetic_condition')->nullable();
            $table->date('due_at')->nullable();
            $table->decimal('estimated_quote_amount', 22, 4)->nullable();
            $table->json('warranty_json')->nullable();
            $table->string('access_status', 40)->default('NO_LOCK');
            $table->text('customer_facing_update')->nullable();
        });

        Schema::create('recommerce_repair_checklist_items', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('business_id');
            $table->unsignedInteger('location_id');
            $table->unsignedBigInteger('repair_job_id');
            $table->string('check_key', 64);
            $table->string('label', 160);
            $table->string('outcome', 24);
            $table->text('notes')->nullable();
            $table->unsignedInteger('observed_by')->nullable();
            $table->dateTime('observed_at');
            $table->timestamps();

            $table->unique(['repair_job_id', 'check_key'], 'recommerce_repair_checklist_unique');
            $table->index(['business_id', 'location_id', 'repair_job_id'], 'rc_repair_checklist_job_idx');
            $table->foreign('business_id')->references('id')->on('business')->onDelete('cascade');
            $table->foreign('location_id')->references('id')->on('business_locations')->onDelete('cascade');
            $table->foreign('repair_job_id')->references('id')->on('recommerce_repair_jobs')->onDelete('cascade');
            $table->foreign('observed_by')->references('id')->on('users')->onDelete('set null');
        });

        Schema::create('recommerce_repair_state_transitions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('business_id');
            $table->unsignedInteger('location_id');
            $table->unsignedBigInteger('repair_job_id');
            $table->uuid('transition_uuid');
            $table->uuid('command_uuid')->nullable();
            $table->string('from_state', 32)->nullable();
            $table->string('to_state', 32);
            $table->json('evidence_json')->nullable();
            $table->unsignedInteger('actor_id')->nullable();
            $table->dateTime('occurred_at');
            $table->timestamps();

            $table->unique('transition_uuid');
            $table->index(['business_id', 'location_id', 'repair_job_id', 'occurred_at'], 'recommerce_repair_transition_timeline_idx');
            $table->foreign('business_id')->references('id')->on('business')->onDelete('cascade');
            $table->foreign('location_id')->references('id')->on('business_locations')->onDelete('cascade');
            $table->foreign('repair_job_id')->references('id')->on('recommerce_repair_jobs')->onDelete('cascade');
            $table->foreign('actor_id')->references('id')->on('users')->onDelete('set null');
        });

        Schema::create('recommerce_repair_lookup_tokens', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('business_id');
            $table->unsignedBigInteger('repair_job_id');
            $table->string('token_hash', 64);
            $table->string('token_hint', 16)->nullable();
            $table->string('status', 20)->default('ACTIVE');
            $table->dateTime('issued_at');
            $table->dateTime('revoked_at')->nullable();
            $table->unsignedInteger('issued_by')->nullable();
            $table->timestamps();

            $table->unique('token_hash', 'recommerce_repair_lookup_token_hash_unique');
            $table->unique(['repair_job_id', 'status'], 'recommerce_repair_lookup_active_unique');
            $table->index(['business_id', 'repair_job_id', 'status'], 'rc_repair_lookup_status_idx');
            $table->foreign('business_id')->references('id')->on('business')->onDelete('cascade');
            $table->foreign('repair_job_id')->references('id')->on('recommerce_repair_jobs')->onDelete('cascade');
            $table->foreign('issued_by')->references('id')->on('users')->onDelete('set null');
        });
    }

    public function down()
    {
        Schema::dropIfExists('recommerce_repair_lookup_tokens');
        Schema::dropIfExists('recommerce_repair_state_transitions');
        Schema::dropIfExists('recommerce_repair_checklist_items');
        Schema::table('recommerce_repair_jobs', function (Blueprint $table) {
            $table->dropColumn([
                'command_hash',
                'reported_fault', 'cosmetic_condition', 'due_at', 'estimated_quote_amount',
                'warranty_json', 'access_status', 'customer_facing_update',
            ]);
        });
    }
};
