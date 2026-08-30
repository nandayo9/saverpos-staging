<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Archive legacy provider repair transactions as evidence. This is a
     * read-only copy; the original POS transaction is never modified.
     */
    public function up()
    {
        Schema::create('recommerce_repair_archives', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('business_id');
            $table->unsignedInteger('location_id')->nullable();
            $table->unsignedInteger('transaction_id')->nullable();
            $table->string('source_sub_type', 40)->default('repair');
            $table->uuid('archive_uuid');
            $table->uuid('command_uuid');
            $table->string('invoice_no')->nullable();
            $table->string('status', 40)->nullable();
            $table->dateTime('transaction_date')->nullable();
            $table->decimal('total_amount', 22, 4)->nullable();
            $table->unsignedInteger('contact_id')->nullable();
            $table->json('snapshot_json');
            $table->string('snapshot_sha256', 64);
            $table->dateTime('archived_at');
            $table->unsignedInteger('archived_by')->nullable();
            $table->timestamps();

            $table->unique('archive_uuid');
            $table->unique('transaction_id', 'recommerce_repair_archive_transaction_unique');
            $table->index(['business_id', 'location_id', 'archived_at'], 'recommerce_repair_archive_scope_idx');
            $table->foreign('business_id')->references('id')->on('business')->onDelete('cascade');
            $table->foreign('location_id')->references('id')->on('business_locations')->onDelete('cascade');
            // Preserve the immutable snapshot if the authoritative POS row is
            // later deleted; the nullable FK is only a navigational link.
            $table->foreign('transaction_id')->references('id')->on('transactions')->onDelete('set null');
            $table->foreign('contact_id')->references('id')->on('contacts')->onDelete('set null');
            $table->foreign('archived_by')->references('id')->on('users')->onDelete('set null');
        });
    }

    public function down()
    {
        Schema::dropIfExists('recommerce_repair_archives');
    }
};
