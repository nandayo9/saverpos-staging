<?php

namespace Modules\Recommerce\Entities;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class RepairPartUsage extends Model
{
    protected $table = 'recommerce_repair_part_usages';

    protected $guarded = ['id', 'usage_uuid', 'command_uuid', 'status', 'quantity', 'consumption_path'];

    protected $casts = [
        'quantity' => 'decimal:4',
        'issued_at' => 'datetime',
        'installed_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    public function job(): BelongsTo
    {
        return $this->belongsTo(RepairJob::class, 'repair_job_id');
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(RepairPartReservation::class, 'reservation_id');
    }

    public function costEntry(): HasOne
    {
        return $this->hasOne(RepairCostEntry::class, 'part_usage_id');
    }

    public function variation(): BelongsTo
    {
        return $this->belongsTo(\App\Variation::class, 'variation_id');
    }
}
