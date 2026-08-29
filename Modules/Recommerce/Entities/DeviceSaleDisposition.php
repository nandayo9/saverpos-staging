<?php

namespace Modules\Recommerce\Entities;

use Illuminate\Database\Eloquent\Model;

class DeviceSaleDisposition extends Model
{
    protected $table = 'recommerce_device_sale_dispositions';

    protected $guarded = ['id'];

    protected $casts = [
        'sold_at' => 'datetime',
        'reversed_at' => 'datetime',
    ];
}
