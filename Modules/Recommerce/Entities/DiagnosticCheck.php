<?php

namespace Modules\Recommerce\Entities;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DiagnosticCheck extends Model
{
    protected $table = 'recommerce_diagnostic_checks';

    protected $guarded = ['id'];

    protected $casts = [
        'minimum_value' => 'decimal:4',
        'maximum_value' => 'decimal:4',
        'allowed_outcomes_json' => 'array',
        'is_required' => 'boolean',
        'evidence_required' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function templateVersion(): BelongsTo
    {
        return $this->belongsTo(DiagnosticTemplateVersion::class, 'template_version_id');
    }

    public function snapshot(): array
    {
        return [
            'check_key' => $this->check_key,
            'label' => $this->label,
            'outcome_type' => $this->outcome_type,
            'unit' => $this->unit,
            'minimum_value' => $this->minimum_value,
            'maximum_value' => $this->maximum_value,
            'allowed_outcomes' => $this->allowed_outcomes_json,
            'is_required' => (bool) $this->is_required,
            'evidence_required' => (bool) $this->evidence_required,
            'sort_order' => (int) $this->sort_order,
        ];
    }
}
