<?php

namespace Modules\Recommerce\Entities;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DiagnosticTemplate extends Model
{
    protected $table = 'recommerce_diagnostic_templates';

    protected $guarded = ['id', 'template_uuid'];

    protected $casts = [
        'location_id' => 'integer',
    ];

    public function versions(): HasMany
    {
        return $this->hasMany(DiagnosticTemplateVersion::class, 'template_id');
    }
}
