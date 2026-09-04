<?php

namespace Modules\Recommerce\Entities;

use Illuminate\Database\Eloquent\Model;

class TradeInNegotiationEvent extends Model
{
    public const SYSTEM_RECOMMENDATION = 'SYSTEM_RECOMMENDATION';
    public const STAFF_OFFER = 'STAFF_OFFER';
    public const CUSTOMER_COUNTER = 'CUSTOMER_COUNTER';
    public const MANAGER_APPROVAL = 'MANAGER_APPROVAL';
    public const MANAGER_REVISION_REQUESTED = 'MANAGER_REVISION_REQUESTED';
    public const FINAL_ACCEPTED = 'FINAL_ACCEPTED';
    public const FINAL_REJECTED = 'FINAL_REJECTED';

    protected $table = 'recommerce_trade_in_negotiation_events';

    protected $guarded = ['id'];

    protected $casts = ['amount' => 'decimal:4', 'occurred_at' => 'datetime'];
}
