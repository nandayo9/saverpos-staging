<?php

namespace Modules\Recommerce\Entities;

use Illuminate\Database\Eloquent\Model;

class TradeInAuthorityRule extends Model
{
    protected $table = 'recommerce_trade_in_authority_rules';

    protected $guarded = ['id'];

    protected $casts = ['maximum_without_approval' => 'decimal:4', 'active' => 'boolean'];
}
