<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('recommerce_repair_jobs', function (Blueprint $table) {
            // A repeat visit links the new job to the closed original without
            // reopening or rewriting that job's recorded history.
            $table->unsignedBigInteger('parent_repair_job_id')->nullable()->after('device_id');
            $table->index(['business_id', 'parent_repair_job_id'], 'recommerce_repair_job_repeat_idx');
            $table->foreign('parent_repair_job_id', 'recommerce_repair_job_parent_fk')
                ->references('id')->on('recommerce_repair_jobs')->onDelete('set null');
        });
    }

    public function down()
    {
        Schema::table('recommerce_repair_jobs', function (Blueprint $table) {
            $table->dropForeign('recommerce_repair_job_parent_fk');
            $table->dropColumn('parent_repair_job_id');
        });
    }
};
