<?php

namespace Modules\Recommerce\Entities;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class TradeInCustomerDecision extends Model
{
    protected $table = 'recommerce_trade_in_customer_decisions';
    protected $guarded = ['id'];
    protected $casts = ['decided_at' => 'datetime'];
    public function offer(): BelongsTo { return $this->belongsTo(TradeInApprovedOffer::class, 'offer_id'); }
}
