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

        foreach ((array) config('walkin.permissions', []) as $name) {
            DB::table('permissions')->updateOrInsert(
                ['name' => $name, 'guard_name' => 'web'],
                ['created_at' => now(), 'updated_at' => now()]
            );
        }
    }

    public function down()
    {
        if (Schema::hasTable('permissions')) {
            DB::table('permissions')->where('guard_name', 'web')
                ->whereIn('name', (array) config('walkin.permissions', []))->delete();
        }
    }
};
