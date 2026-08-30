<?php

namespace Modules\Recommerce\Entities;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RepairArchive extends Model
{
    protected $table = 'recommerce_repair_archives';

    /** Raw snapshot material is stored only in the immutable JSON column. */
    protected $guarded = ['id', 'archive_uuid', 'snapshot_json', 'snapshot_sha256'];

    protected $casts = [
        'snapshot_json' => 'array',
    ];

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(\App\Transaction::class, 'transaction_id');
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(\App\Contact::class, 'contact_id');
    }
}
