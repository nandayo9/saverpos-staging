<?php

namespace Modules\Recommerce\Entities;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeviceAcquisition extends Model
{
    protected $table = 'recommerce_device_acquisitions';

    protected $guarded = ['id'];

    protected $casts = [
        'acquisition_amount' => 'decimal:4',
        'posted_at' => 'datetime',
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class, 'device_id');
    }

    public function valuation(): BelongsTo
    {
        return $this->belongsTo(TradeInValuation::class, 'trade_in_valuation_id');
    }
}
