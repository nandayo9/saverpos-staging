<?php

namespace Modules\Recommerce\Entities;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DeviceInspection extends Model
{
    protected $table = 'recommerce_device_inspections';

    protected $guarded = ['id'];

    protected $casts = [
        'received_at' => 'datetime',
        'assigned_at' => 'datetime',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class, 'device_id');
    }

    public function observations(): HasMany
    {
        return $this->hasMany(DeviceIntakeObservation::class, 'inspection_id');
    }
}
