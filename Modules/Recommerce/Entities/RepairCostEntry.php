<?php

namespace Modules\Recommerce\Entities;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RepairCostEntry extends Model
{
    protected $table = 'recommerce_repair_cost_entries';

    protected $guarded = ['id', 'cost_uuid', 'source_key', 'amount', 'recorded_at'];

    protected $casts = [
        'amount' => 'decimal:4',
        'recorded_at' => 'datetime',
    ];

    public function job(): BelongsTo
    {
        return $this->belongsTo(RepairJob::class, 'repair_job_id');
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class, 'device_id');
    }

    public function partUsage(): BelongsTo
    {
        return $this->belongsTo(RepairPartUsage::class, 'part_usage_id');
    }

    public function reversalOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversal_of_id');
    }
}
