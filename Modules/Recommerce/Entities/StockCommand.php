<?php

namespace Modules\Recommerce\Entities;

use Illuminate\Database\Eloquent\Model;

class StockCommand extends Model
{
    protected $table = 'recommerce_stock_commands';

    protected $guarded = ['id'];

    protected $casts = [
        'result_json' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];
}
