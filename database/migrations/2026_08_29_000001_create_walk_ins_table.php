<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('walk_ins', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('business_id');
            $table->unsignedInteger('location_id');
            $table->dateTime('arrived_at');
            $table->unsignedInteger('recorded_by');
            $table->string('status', 20)->default('OPEN');
            $table->string('no_sale_reason', 64)->nullable();
            $table->unsignedInteger('transaction_id')->nullable()->unique();
            $table->dateTime('converted_at')->nullable();
            $table->dateTime('closed_at')->nullable();
            $table->unsignedInteger('closed_by')->nullable();
            $table->unsignedInteger('updated_by')->nullable();
            $table->timestamps();

            $table->foreign('business_id')->references('id')->on('business')->onDelete('cascade');
            $table->foreign('location_id')->references('id')->on('business_locations')->onDelete('restrict');
            $table->foreign('recorded_by')->references('id')->on('users')->onDelete('restrict');
            $table->foreign('closed_by')->references('id')->on('users')->onDelete('restrict');
            $table->foreign('updated_by')->references('id')->on('users')->onDelete('restrict');
            // A linked sale must be detached through the service before deletion.
            $table->foreign('transaction_id')->references('id')->on('transactions')->onDelete('restrict');

            $table->index(['business_id', 'location_id', 'arrived_at'], 'walk_ins_business_location_arrived_index');
            $table->index(['business_id', 'status', 'arrived_at'], 'walk_ins_business_status_arrived_index');
            $table->index(['business_id', 'no_sale_reason', 'arrived_at'], 'walk_ins_business_reason_arrived_index');
        });
    }

    public function down()
    {
        Schema::dropIfExists('walk_ins');
    }
};
