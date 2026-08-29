<?php

namespace Modules\Recommerce\Entities;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RepairLookupToken extends Model
{
    protected $table = 'recommerce_repair_lookup_tokens';

    protected $guarded = ['id'];

    protected $hidden = ['token_hash'];

    protected $casts = [
        'issued_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function job(): BelongsTo
    {
        return $this->belongsTo(RepairJob::class, 'repair_job_id');
    }
}
