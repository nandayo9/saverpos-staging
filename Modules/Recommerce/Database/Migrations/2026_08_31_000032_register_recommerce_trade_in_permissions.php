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
        foreach ([
            'recommerce.tradein.view',
            'recommerce.tradein.manage',
            'recommerce.tradein.approve',
            'recommerce.tradein.override_economic_ceiling',
            'recommerce.tradein.accept',
            'recommerce.tradein.reverse',
        ] as $permission) {
            DB::table('permissions')->updateOrInsert(
                ['name' => $permission, 'guard_name' => 'web'],
                ['name' => $permission, 'guard_name' => 'web', 'updated_at' => now(), 'created_at' => now()]
            );
        }
    }

    public function down()
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }
        DB::table('permissions')->whereIn('name', [
            'recommerce.tradein.view',
            'recommerce.tradein.manage',
            'recommerce.tradein.approve',
            'recommerce.tradein.override_economic_ceiling',
            'recommerce.tradein.accept',
            'recommerce.tradein.reverse',
        ])->where('guard_name', 'web')->delete();
    }
};
