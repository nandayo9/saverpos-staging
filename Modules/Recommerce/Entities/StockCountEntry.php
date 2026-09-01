<?php

namespace Modules\Recommerce\Entities;

use Illuminate\Database\Eloquent\Model;

class StockCountEntry extends Model
{
    protected $table = 'recommerce_stock_count_entries';
    protected $guarded = ['id'];
    protected $casts = ['quantity' => 'float', 'recorded_at' => 'datetime'];
}
