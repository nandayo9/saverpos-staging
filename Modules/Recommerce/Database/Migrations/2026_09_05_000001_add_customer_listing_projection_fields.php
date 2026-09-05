<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up()
    {
        Schema::table('recommerce_devices', function (Blueprint $table) {
            $table->uuid('public_device_id')->nullable()->after('device_uuid');
            $table->string('listing_publication_state', 20)->default('DRAFT')->after('stock_participation');
            $table->decimal('listing_price', 22, 4)->nullable()->after('listing_publication_state');
            $table->char('listing_currency', 3)->default('MYR')->after('listing_price');
            $table->string('listing_model_slug', 160)->nullable()->after('listing_currency');
            $table->string('listing_specification_id', 96)->nullable()->after('listing_model_slug');
            $table->unique('public_device_id', 'rc_device_public_id_uniq');
            $table->index(['business_id', 'listing_publication_state', 'current_location_id'], 'rc_device_listing_public_lookup_idx');
        });

        DB::table('recommerce_devices')->whereNull('public_device_id')->orderBy('id')->chunkById(200, function ($devices) {
            foreach ($devices as $device) {
                DB::table('recommerce_devices')->where('id', $device->id)->update([
                    'public_device_id' => (string) Str::uuid(),
                ]);
            }
        });
    }

    public function down()
    {
        Schema::table('recommerce_devices', function (Blueprint $table) {
            $table->dropIndex('rc_device_listing_public_lookup_idx');
            $table->dropUnique('rc_device_public_id_uniq');
            $table->dropColumn([
                'public_device_id',
                'listing_publication_state',
                'listing_price',
                'listing_currency',
                'listing_model_slug',
                'listing_specification_id',
            ]);
        });
    }
};
