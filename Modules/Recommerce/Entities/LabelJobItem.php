<?php

namespace Modules\Recommerce\Entities;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LabelJobItem extends Model
{
    protected $table = 'recommerce_label_job_items';

    protected $guarded = ['id'];

    protected $casts = [
        'ordinal' => 'integer',
    ];

    public function job(): BelongsTo
    {
        return $this->belongsTo(LabelJob::class, 'label_job_id');
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class, 'device_id');
    }

    public function scanToken(): BelongsTo
    {
        return $this->belongsTo(ScanToken::class, 'scan_token_id');
    }
}
