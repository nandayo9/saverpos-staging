<?php

namespace Modules\Recommerce\Entities;

use Illuminate\Database\Eloquent\Model;

class TradeInRuleSet extends Model
{
    protected $table = 'recommerce_trade_in_rule_sets';

    protected $guarded = ['id'];

    protected $casts = [
        'parameters_json' => 'array',
        'effective_at' => 'datetime',
        'retired_at' => 'datetime',
    ];
}
