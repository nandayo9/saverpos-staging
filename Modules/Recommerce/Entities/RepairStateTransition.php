<?php

namespace Modules\Recommerce\Entities;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RepairStateTransition extends Model
{
    protected $table = 'recommerce_repair_state_transitions';

    protected $guarded = ['id'];

    protected $casts = [
        'evidence_json' => 'array',
        'occurred_at' => 'datetime',
    ];

    public function job(): BelongsTo
    {
        return $this->belongsTo(RepairJob::class, 'repair_job_id');
    }
}
