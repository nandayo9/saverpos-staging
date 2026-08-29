<?php

namespace Modules\Recommerce\Entities;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustodyPeriod extends Model
{
    protected $table = 'recommerce_device_custody_periods';

    protected $guarded = ['id'];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class, 'device_id');
    }

    public function sourceMovement(): BelongsTo
    {
        return $this->belongsTo(DeviceMovement::class, 'source_movement_id');
    }
}
