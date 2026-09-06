<?php

namespace Modules\Recommerce\Entities;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Durable catalogue provenance for a variation created from a trade-in.
 *
 * It deliberately sits alongside UltimatePOS's catalogue tables: products,
 * variations and purchase lines remain the commercial source of truth.
 */
class TradeInCatalogueOrigin extends Model
{
    public const ORIGIN_TRADE_IN = 'TRADE_IN';

    protected $table = 'recommerce_trade_in_catalogue_origins';

    protected $guarded = ['id'];

    protected $casts = [
        'specifications_json' => 'array',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(\App\Product::class, 'product_id');
    }

    public function variation(): BelongsTo
    {
        return $this->belongsTo(\App\Variation::class, 'variation_id');
    }

    public function quickQuote(): BelongsTo
    {
        return $this->belongsTo(TradeInQuickQuote::class, 'created_from_quick_quote_id');
    }

    public function valuation(): BelongsTo
    {
        return $this->belongsTo(TradeInValuation::class, 'created_from_trade_in_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(\App\User::class, 'created_by');
    }
}
