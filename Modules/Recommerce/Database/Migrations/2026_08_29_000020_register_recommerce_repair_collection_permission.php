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

        $permissions = array_values(array_filter(
            (array) config('recommerce.permissions', []),
            fn (string $name): bool => in_array($name, [
                'recommerce.repair.collection',
                'recommerce.repair.collection.override',
            ], true)
        ));

        $now = now();
        foreach ($permissions as $name) {
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
            ->whereIn('name', ['recommerce.repair.collection', 'recommerce.repair.collection.override'])
            ->delete();
    }
};
