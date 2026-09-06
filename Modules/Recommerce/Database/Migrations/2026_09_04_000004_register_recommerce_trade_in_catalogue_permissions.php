<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const PERMISSIONS = [
        'recommerce.tradein.catalogue_create',
        'recommerce.tradein.catalogue_override_duplicate',
    ];

    public function up()
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        foreach (self::PERMISSIONS as $permission) {
            DB::table('permissions')->updateOrInsert(
                ['name' => $permission, 'guard_name' => 'web'],
                ['name' => $permission, 'guard_name' => 'web', 'created_at' => now(), 'updated_at' => now()]
            );
        }
    }

    public function down()
    {
        if (Schema::hasTable('permissions')) {
            DB::table('permissions')->where('guard_name', 'web')->whereIn('name', self::PERMISSIONS)->delete();
        }
    }
};
