<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        DB::table('permissions')->updateOrInsert(
            ['name' => 'recommerce.repair.archive', 'guard_name' => 'web'],
            ['created_at' => now(), 'updated_at' => now()]
        );
    }

    public function down()
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        DB::table('permissions')
            ->where('guard_name', 'web')
            ->where('name', 'recommerce.repair.archive')
            ->delete();
    }
};
