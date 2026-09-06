<?php

namespace Modules\Recommerce\Entities;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

final class TradeInApprovedOffer extends Model
{
    protected $table = 'recommerce_trade_in_approved_offers';
    protected $guarded = ['id'];
    protected $casts = ['offer_version' => 'integer', 'amount' => 'decimal:4', 'customer_projection_json' => 'array', 'approved_at' => 'datetime', 'published_at' => 'datetime'];

    public function intake(): BelongsTo { return $this->belongsTo(TradeInIntake::class, 'intake_id'); }
    public function valuation(): BelongsTo { return $this->belongsTo(TradeInValuation::class, 'valuation_id'); }
    public function decision(): HasOne { return $this->hasOne(TradeInCustomerDecision::class, 'offer_id'); }
}
