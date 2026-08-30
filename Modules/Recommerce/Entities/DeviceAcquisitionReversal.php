<?php

namespace Modules\Recommerce\Entities;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeviceAcquisitionReversal extends Model
{
    protected $table = 'recommerce_device_acquisition_reversals';

    protected $guarded = ['id'];

    protected $casts = [
        'reversed_at' => 'datetime',
    ];

    public function acquisition(): BelongsTo
    {
        return $this->belongsTo(DeviceAcquisition::class, 'acquisition_id');
    }
}
