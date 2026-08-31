<?php

namespace Modules\Recommerce\Entities;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeviceTransferAssignment extends Model
{
    protected $table = 'recommerce_device_transfer_assignments';

    protected $guarded = ['id'];

    protected $casts = [
        'transferred_at' => 'datetime',
        'dispatched_at' => 'datetime',
        'received_at' => 'datetime',
        'reversed_at' => 'datetime',
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class, 'device_id');
    }
}
