<?php

namespace Modules\Recommerce\Entities;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class RepairPartReservation extends Model
{
    protected $table = 'recommerce_repair_part_reservations';

    protected $guarded = ['id', 'command_uuid', 'status', 'quantity'];

    protected $casts = [
        'quantity' => 'decimal:4',
        'reserved_at' => 'datetime',
        'expires_at' => 'datetime',
        'released_at' => 'datetime',
    ];

    public function job(): BelongsTo
    {
        return $this->belongsTo(RepairJob::class, 'repair_job_id');
    }

    public function usage(): HasOne
    {
        return $this->hasOne(RepairPartUsage::class, 'reservation_id');
    }

    public function variation(): BelongsTo
    {
        return $this->belongsTo(\App\Variation::class, 'variation_id');
    }
}
