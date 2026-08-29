<?php

namespace Modules\Recommerce\Entities;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DevicePurchaseAssignment extends Model
{
    protected $table = 'recommerce_device_purchase_assignments';

    protected $guarded = ['id'];

    protected $casts = [
        'unit_acquisition_cost' => 'decimal:4',
        'landed_allocation' => 'decimal:4',
        'assigned_at' => 'datetime',
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class, 'device_id');
    }
}
