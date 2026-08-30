<?php

namespace Modules\Recommerce\Entities;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WarrantyClaimLine extends Model
{
    protected $table = 'recommerce_warranty_claim_lines';

    protected $guarded = ['id'];

    public function claim(): BelongsTo
    {
        return $this->belongsTo(WarrantyClaim::class, 'warranty_claim_id');
    }
}
