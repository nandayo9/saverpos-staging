<?php

namespace Modules\Recommerce\Entities;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DiagnosticTemplateVersion extends Model
{
    protected $table = 'recommerce_diagnostic_template_versions';

    protected $guarded = ['id', 'version_number', 'status', 'published_at', 'retired_at'];

    protected $casts = [
        'rubric_json' => 'array',
        'published_at' => 'datetime',
        'retired_at' => 'datetime',
        'version_number' => 'integer',
    ];

    public function template(): BelongsTo
    {
        return $this->belongsTo(DiagnosticTemplate::class, 'template_id');
    }

    public function checks(): HasMany
    {
        return $this->hasMany(DiagnosticCheck::class, 'template_version_id')->orderBy('sort_order');
    }

    public function isPublished(): bool
    {
        return $this->status === 'PUBLISHED';
    }

    public function snapshot(): array
    {
        return [
            'template_code' => $this->template?->template_code,
            'template_name' => $this->template?->name,
            'version_number' => (int) $this->version_number,
            'rubric' => $this->rubric_json,
            'checks' => $this->checks->map(fn (DiagnosticCheck $check): array => $check->snapshot())->values()->all(),
        ];
    }
}
