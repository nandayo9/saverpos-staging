<?php

namespace Modules\Recommerce\Entities;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StockCountSession extends Model
{
    protected $table = 'recommerce_stock_count_sessions';
    protected $guarded = ['id'];
    protected $casts = ['scope_json' => 'array', 'blind_count' => 'boolean', 'snapshot_at' => 'datetime', 'started_at' => 'datetime', 'approved_at' => 'datetime', 'reconciled_at' => 'datetime', 'closed_at' => 'datetime'];

    public function items(): HasMany { return $this->hasMany(StockCountItem::class, 'session_id'); }
    public function entries(): HasMany { return $this->hasMany(StockCountEntry::class, 'session_id'); }
    public function exceptions(): HasMany { return $this->hasMany(StockCountException::class, 'session_id'); }
    public function audits(): HasMany { return $this->hasMany(StockCountAudit::class, 'session_id'); }
}
