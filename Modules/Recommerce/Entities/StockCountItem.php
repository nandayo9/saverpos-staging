<?php

namespace Modules\Recommerce\Entities;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockCountItem extends Model
{
    protected $table = 'recommerce_stock_count_items';
    protected $guarded = ['id'];
    protected $casts = ['expected_quantity' => 'float', 'counted_quantity' => 'float', 'reconciled_quantity' => 'float', 'snapshot_json' => 'array', 'counted_at' => 'datetime'];
    public function session(): BelongsTo { return $this->belongsTo(StockCountSession::class, 'session_id'); }
    public function device(): BelongsTo { return $this->belongsTo(Device::class, 'device_id'); }
}
