<?php

namespace Modules\Recommerce\Entities;

use Illuminate\Database\Eloquent\Model;

class TradeInSellerRepresentation extends Model
{
    protected $table = 'recommerce_trade_in_seller_representations';

    protected $guarded = ['id'];
}
