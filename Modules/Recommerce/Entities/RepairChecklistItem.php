<?php

namespace Modules\Recommerce\Entities;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RepairChecklistItem extends Model
{
    protected $table = 'recommerce_repair_checklist_items';

    protected $guarded = ['id'];

    protected $casts = ['observed_at' => 'datetime'];

    public function job(): BelongsTo
    {
        return $this->belongsTo(RepairJob::class, 'repair_job_id');
    }
}
