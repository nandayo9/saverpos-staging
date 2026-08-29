<?php

namespace Modules\Recommerce\Entities;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeviceCertification extends Model
{
    protected $table = 'recommerce_device_certifications';

    protected $guarded = ['id'];

    protected $casts = [
        'qc_passed' => 'boolean',
        'battery_health_percent' => 'integer',
        'purchased_at' => 'datetime',
        'warranty_expires_at' => 'datetime',
        'published_at' => 'datetime',
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class, 'device_id');
    }
}
