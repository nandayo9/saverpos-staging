<?php

namespace Modules\Recommerce\Entities;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RepairQuoteLine extends Model
{
    protected $table = 'recommerce_repair_quote_lines';

    protected $guarded = [
        'id',
        'quote_id',
        'business_id',
        'location_id',
        'line_total_amount',
    ];

    /**
     * Scope and quote ownership are service-controlled; line_total_amount is a
     * derived, system-computed total so neither may come from request input.
     */

    protected $casts = [
        'quantity' => 'decimal:4',
        'unit_amount' => 'decimal:4',
        'tax_amount' => 'decimal:4',
        'line_total_amount' => 'decimal:4',
        'sort_order' => 'integer',
    ];

    public function quote(): BelongsTo
    {
        return $this->belongsTo(RepairQuote::class, 'quote_id');
    }
}
