<?php

namespace Modules\Recommerce\Entities;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DiagnosticSession extends Model
{
    protected $table = 'recommerce_diagnostic_sessions';

    protected $guarded = ['id', 'session_uuid', 'status', 'template_snapshot_json', 'submitted_at'];

    protected $casts = [
        'template_snapshot_json' => 'array',
        'submitted_at' => 'datetime',
    ];

    public function job(): BelongsTo
    {
        return $this->belongsTo(RepairJob::class, 'repair_job_id');
    }

    public function templateVersion(): BelongsTo
    {
        return $this->belongsTo(DiagnosticTemplateVersion::class, 'template_version_id');
    }

    public function observations(): HasMany
    {
        return $this->hasMany(DiagnosticObservation::class, 'session_id');
    }
}
