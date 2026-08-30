<?php

namespace Modules\Recommerce\Entities;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TradeInMarketEvidence extends Model
{
    protected $table = 'recommerce_trade_in_market_evidence';

    protected $guarded = ['id'];

    protected $casts = [
        'reference_amount' => 'decimal:4',
        'observed_at' => 'datetime',
    ];

    public function valuation(): BelongsTo
    {
        return $this->belongsTo(TradeInValuation::class, 'valuation_id');
    }
}
