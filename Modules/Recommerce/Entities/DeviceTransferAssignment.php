<?php

namespace Modules\Recommerce\Entities;

use Illuminate\Database\Eloquent\Model;

class DeviceTransferAssignment extends Model
{
    protected $table = 'recommerce_device_transfer_assignments';

    protected $guarded = ['id'];

    protected $casts = [
        'transferred_at' => 'datetime',
        'reversed_at' => 'datetime',
    ];
}
