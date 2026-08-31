<?php

namespace Modules\Recommerce\Entities;

use Illuminate\Database\Eloquent\Model;

class TradeInLaptopInspection extends Model
{
    protected $table = 'recommerce_trade_in_laptop_inspections';

    protected $guarded = ['id'];

    protected $casts = [
        'functional_checks_json' => 'array',
        'accessories_json' => 'array',
        'risk_flags_json' => 'array',
        'battery_health_percent' => 'decimal:2',
        'battery_replacement_estimate_amount' => 'decimal:4',
    ];
}
