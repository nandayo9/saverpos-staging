<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('recommerce_trade_in_quick_quotes', function (Blueprint $table) {
            // Existing rows (all created through the laptop-only wizard) keep working
            // unchanged: default category_code = LAPTOP, default channel = STAFF.
            $table->string('category_code', 32)->default('LAPTOP')->after('variation_id');
            $table->string('channel', 20)->default('STAFF')->after('category_code');
            $table->string('market_price_source', 20)->nullable()->after('expected_resale_amount');
            $table->dateTime('market_price_fetched_at')->nullable()->after('market_price_source');

            $table->index(['business_id', 'location_id', 'channel'], 'rc_tradein_quick_quote_channel_idx');
        });
    }

    public function down()
    {
        Schema::table('recommerce_trade_in_quick_quotes', function (Blueprint $table) {
            $table->dropIndex('rc_tradein_quick_quote_channel_idx');
            $table->dropColumn(['category_code', 'channel', 'market_price_source', 'market_price_fetched_at']);
        });
    }
};
