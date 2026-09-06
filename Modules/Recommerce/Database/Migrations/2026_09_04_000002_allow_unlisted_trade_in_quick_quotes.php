<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        if (! Schema::hasTable('recommerce_trade_in_quick_quotes')) {
            return;
        }

        // Fresh SQLite fixtures use the nullable columns declared in the
        // create migration above. Production uses MySQL, where MODIFY keeps
        // the existing foreign keys while allowing a temporary, unlisted
        // Quick Quote to have no catalogue product or variation.
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        DB::statement('ALTER TABLE `recommerce_trade_in_quick_quotes` MODIFY `product_id` INT UNSIGNED NULL, MODIFY `variation_id` INT UNSIGNED NULL');
    }

    public function down()
    {
        if (! Schema::hasTable('recommerce_trade_in_quick_quotes') || DB::getDriverName() === 'sqlite') {
            return;
        }

        if (DB::table('recommerce_trade_in_quick_quotes')->whereNull('product_id')->orWhereNull('variation_id')->exists()) {
            throw new LogicException('Cannot roll back while unlisted Quick Quotes exist. Resolve or remove them first.');
        }

        DB::statement('ALTER TABLE `recommerce_trade_in_quick_quotes` MODIFY `product_id` INT UNSIGNED NOT NULL, MODIFY `variation_id` INT UNSIGNED NOT NULL');
    }
};
