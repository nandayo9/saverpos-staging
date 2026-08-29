<?php

namespace Modules\Recommerce\Entities;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeviceIdentifier extends Model
{
    protected $table = 'recommerce_device_identifiers';

    protected $guarded = ['id'];

    protected $casts = [
        'raw_value_encrypted' => 'encrypted',
        'is_verified' => 'boolean',
        'verified_at' => 'datetime',
    ];

    protected $hidden = [
        'raw_value_encrypted',
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class, 'device_id');
    }
}
