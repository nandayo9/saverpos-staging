<?php

namespace Modules\Recommerce\Entities;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeviceCostOverrideEvent extends Model
{
    protected $table = 'recommerce_device_cost_override_events';

    protected $guarded = ['id'];

    protected $casts = [
        'previous_unit_acquisition_cost' => 'decimal:4',
        'new_unit_acquisition_cost' => 'decimal:4',
        'overridden_at' => 'datetime',
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class, 'device_id');
    }
}
