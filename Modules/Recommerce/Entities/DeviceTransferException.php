<?php

namespace Modules\Recommerce\Entities;

use Illuminate\Database\Eloquent\Model;

class DeviceTransferException extends Model
{
    protected $table = 'recommerce_device_transfer_exceptions';

    protected $guarded = ['id'];

    protected $casts = [
        'resolved_at' => 'datetime',
    ];
}
