<?php

namespace Modules\Recommerce\Entities;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeviceEvent extends Model
{
    protected $table = 'recommerce_device_events';

    protected $guarded = ['id'];

    protected $casts = [
        'event_version' => 'integer',
        'metadata_json' => 'array',
        'occurred_at' => 'datetime',
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class, 'device_id');
    }
}
