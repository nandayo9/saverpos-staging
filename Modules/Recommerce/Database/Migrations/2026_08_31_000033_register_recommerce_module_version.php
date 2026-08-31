<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('system')) {
            return;
        }

        DB::table('system')->updateOrInsert(
            ['key' => 'recommerce_version'],
            ['value' => (string) config('recommerce.module_version', '1.0.0')]
        );
    }

    public function down(): void
    {
        if (! Schema::hasTable('system')) {
            return;
        }

        DB::table('system')
            ->where('key', 'recommerce_version')
            ->where('value', (string) config('recommerce.module_version', '1.0.0'))
            ->delete();
    }
};
