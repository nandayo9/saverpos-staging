<?php

namespace Modules\Recommerce\Entities;

use Illuminate\Database\Eloquent\Model;

class DeviceReturnDisposition extends Model
{
    protected $table = 'recommerce_device_return_dispositions';

    protected $guarded = ['id'];

    protected $casts = [
        'returned_at' => 'datetime',
        'reversed_at' => 'datetime',
    ];
}
