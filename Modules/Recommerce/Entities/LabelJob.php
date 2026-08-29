<?php

namespace Modules\Recommerce\Entities;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LabelJob extends Model
{
    protected $table = 'recommerce_label_jobs';

    protected $guarded = ['id'];

    protected $casts = [
        'item_count' => 'integer',
        'request_json' => 'array',
        'expires_at' => 'datetime',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(LabelJobItem::class, 'label_job_id');
    }
}
