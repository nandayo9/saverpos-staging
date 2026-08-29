<?php

namespace Modules\Recommerce\Entities;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DiagnosticObservation extends Model
{
    protected $table = 'recommerce_diagnostic_observations';

    protected $guarded = ['id'];

    protected $casts = [
        'value_numeric' => 'decimal:4',
        'evidence_json' => 'array',
        'observed_at' => 'datetime',
    ];

    public function session(): BelongsTo
    {
        return $this->belongsTo(DiagnosticSession::class, 'session_id');
    }

    public function check(): BelongsTo
    {
        return $this->belongsTo(DiagnosticCheck::class, 'diagnostic_check_id');
    }
}
