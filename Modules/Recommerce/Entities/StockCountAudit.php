<?php

namespace Modules\Recommerce\Entities;

use Illuminate\Database\Eloquent\Model;

class StockCountAudit extends Model
{
    protected $table = 'recommerce_stock_count_audits';
    protected $guarded = ['id'];
    protected $casts = ['metadata_json' => 'array', 'occurred_at' => 'datetime'];
}
