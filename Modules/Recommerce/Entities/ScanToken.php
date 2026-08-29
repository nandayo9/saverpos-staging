<?php

namespace Modules\Recommerce\Entities;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScanToken extends Model
{
    protected $table = 'recommerce_scan_tokens';

    protected $guarded = ['id'];

    protected $hidden = [
        'token_hash',
    ];

    protected $casts = [
        'issued_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class, 'device_id');
    }
}
