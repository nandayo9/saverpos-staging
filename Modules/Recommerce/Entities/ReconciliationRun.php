<?php

namespace Modules\Recommerce\Entities;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ReconciliationRun extends Model
{
    protected $table = 'recommerce_reconciliation_runs';

    protected $guarded = ['id'];

    protected $casts = [
        'as_of' => 'datetime',
        'core_quantity' => 'float',
        'tracked_device_count' => 'integer',
        'in_transfer_device_count' => 'integer',
        'approved_legacy_balance' => 'float',
        'difference' => 'float',
        'snapshot_json' => 'array',
    ];

    public function issue(): HasOne
    {
        return $this->hasOne(ReconciliationIssue::class, 'reconciliation_run_id');
    }
}
