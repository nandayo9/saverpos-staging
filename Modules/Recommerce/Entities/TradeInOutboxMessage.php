<?php

namespace Modules\Recommerce\Entities;

use Illuminate\Database\Eloquent\Model;

final class TradeInOutboxMessage extends Model
{
    protected $table = 'recommerce_trade_in_outbox_messages';
    protected $guarded = ['id'];
    protected $casts = [
        'payload_json' => 'array',
        'available_at' => 'datetime',
        'last_attempt_at' => 'datetime',
        'delivered_at' => 'datetime',
    ];
}
