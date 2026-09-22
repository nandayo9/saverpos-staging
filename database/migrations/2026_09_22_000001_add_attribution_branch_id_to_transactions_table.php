<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->integer('attribution_branch_id')->unsigned()->nullable()->after('location_id');
            $table->foreign('attribution_branch_id')->references('id')->on('business_locations');

            $table->index('attribution_branch_id');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropForeign(['attribution_branch_id']);
            $table->dropColumn('attribution_branch_id');
        });
    }
};
