<?php

namespace Modules\Recommerce\Entities;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeviceIntakeObservation extends Model
{
    protected $table = 'recommerce_device_intake_observations';

    protected $guarded = ['id'];

    protected $casts = ['recorded_at' => 'datetime'];

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class, 'device_id');
    }

    public function inspection(): BelongsTo
    {
        return $this->belongsTo(DeviceInspection::class, 'inspection_id');
    }
}
