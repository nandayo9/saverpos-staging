<?php

namespace Modules\Recommerce\Entities;

use Illuminate\Database\Eloquent\Model;

class OutboxMessage extends Model
{
    protected $table = 'recommerce_outbox_messages';

    protected $guarded = ['id'];

    protected $casts = [
        'payload_json' => 'array',
        'available_at' => 'datetime',
        'processed_at' => 'datetime',
    ];
}
