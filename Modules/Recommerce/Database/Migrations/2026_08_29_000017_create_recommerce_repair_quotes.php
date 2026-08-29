<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('recommerce_repair_quotes', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('business_id');
            $table->unsignedInteger('location_id');
            $table->unsignedBigInteger('repair_job_id');
            $table->uuid('quote_uuid');
            $table->uuid('command_uuid')->nullable();
            $table->unsignedInteger('version_number');
            $table->string('status', 20)->default('DRAFT');
            $table->string('summary', 320)->nullable();
            $table->json('tax_assumptions_json')->nullable();
            $table->json('terms_json')->nullable();
            $table->decimal('subtotal_amount', 22, 4)->default(0);
            $table->decimal('tax_amount', 22, 4)->default(0);
            $table->decimal('total_amount', 22, 4);
            $table->string('currency', 12)->nullable();
            $table->dateTime('expires_at')->nullable();
            $table->dateTime('sent_at')->nullable();
            $table->string('sent_channel', 40)->nullable();
            $table->unsignedInteger('sent_by')->nullable();
            $table->dateTime('decided_at')->nullable();
            $table->unsignedInteger('decided_by')->nullable();
            $table->json('decision_evidence_json')->nullable();
            $table->text('decision_note')->nullable();
            $table->unsignedBigInteger('superseded_by_quote_id')->nullable();
            $table->unsignedInteger('created_by')->nullable();
            $table->unsignedInteger('updated_by')->nullable();
            $table->timestamps();

            $table->unique('quote_uuid');
            $table->unique(['repair_job_id', 'version_number'], 'recommerce_repair_quote_version_unique');
            $table->unique(['business_id', 'command_uuid'], 'recommerce_repair_quote_command_unique');
            $table->index(['business_id', 'repair_job_id', 'status'], 'recommerce_repair_quote_job_idx');
            $table->index(['business_id', 'status'], 'recommerce_repair_quote_status_idx');
            $table->foreign('business_id')->references('id')->on('business')->onDelete('cascade');
            $table->foreign('location_id')->references('id')->on('business_locations')->onDelete('cascade');
            $table->foreign('repair_job_id')->references('id')->on('recommerce_repair_jobs')->onDelete('restrict');
            $table->foreign('sent_by')->references('id')->on('users')->onDelete('set null');
            $table->foreign('decided_by')->references('id')->on('users')->onDelete('set null');
            $table->foreign('created_by')->references('id')->on('users')->onDelete('set null');
            $table->foreign('updated_by')->references('id')->on('users')->onDelete('set null');
        });

        Schema::table('recommerce_repair_quotes', function (Blueprint $table) {
            $table->foreign('superseded_by_quote_id', 'recommerce_quote_superseded_fk')
                ->references('id')->on('recommerce_repair_quotes')->onDelete('restrict');
        });

        Schema::create('recommerce_repair_quote_lines', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('quote_id');
            $table->unsignedInteger('business_id');
            $table->unsignedInteger('location_id');
            $table->string('line_type', 24);
            $table->string('source_type', 40)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->unsignedInteger('source_line_id')->nullable();
            $table->unsignedBigInteger('variation_id')->nullable();
            $table->string('description', 255);
            $table->decimal('quantity', 22, 4);
            $table->decimal('unit_amount', 22, 4);
            $table->decimal('tax_amount', 22, 4)->default(0);
            $table->decimal('line_total_amount', 22, 4);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['quote_id', 'sort_order'], 'recommerce_quote_line_quote_idx');
            $table->index(['business_id', 'line_type'], 'recommerce_quote_line_type_idx');
            $table->foreign('quote_id')->references('id')->on('recommerce_repair_quotes')->onDelete('cascade');
            $table->foreign('business_id')->references('id')->on('business')->onDelete('cascade');
            $table->foreign('location_id')->references('id')->on('business_locations')->onDelete('cascade');
        });
    }

    public function down()
    {
        Schema::dropIfExists('recommerce_repair_quote_lines');
        Schema::dropIfExists('recommerce_repair_quotes');
    }
};
