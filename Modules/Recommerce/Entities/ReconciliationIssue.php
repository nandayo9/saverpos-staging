<?php

namespace Modules\Recommerce\Entities;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReconciliationIssue extends Model
{
    protected $table = 'recommerce_reconciliation_issues';

    protected $guarded = ['id'];

    protected $casts = [
        'detected_at' => 'datetime',
        'resolved_at' => 'datetime',
        'snapshot_json' => 'array',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(ReconciliationRun::class, 'reconciliation_run_id');
    }
}
