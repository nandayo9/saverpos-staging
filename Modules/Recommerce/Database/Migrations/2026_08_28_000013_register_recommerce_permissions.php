<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        // Fresh Ultimate POS installs create the Spatie permissions table;
        // isolated migration tooling may not. Do not make the Recommerce data
        // schema depend on a role-management table being present.
        if (! Schema::hasTable('permissions')) {
            return;
        }

        $now = now();
        foreach ((array) config('recommerce.permissions', []) as $name) {
            DB::table('permissions')->updateOrInsert(
                ['name' => $name, 'guard_name' => 'web'],
                ['created_at' => $now, 'updated_at' => $now]
            );
        }
    }

    public function down()
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        DB::table('permissions')
            ->where('guard_name', 'web')
            ->whereIn('name', (array) config('recommerce.permissions', []))
            ->delete();
    }
};
