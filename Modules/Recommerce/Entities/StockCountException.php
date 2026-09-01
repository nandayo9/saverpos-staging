<?php

namespace Modules\Recommerce\Entities;

use Illuminate\Database\Eloquent\Model;

class StockCountException extends Model
{
    protected $table = 'recommerce_stock_count_exceptions';
    protected $guarded = ['id'];
    protected $casts = ['context_json' => 'array', 'resolved_at' => 'datetime'];
}
