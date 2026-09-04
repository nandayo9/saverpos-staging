<?php

namespace Modules\Recommerce\Entities;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TradeInQuickQuote extends Model
{
    public const STATUS_CONSIDERING = 'CONSIDERING';
    public const STATUS_CUSTOMER_DECLINED = 'CUSTOMER_DECLINED';
    public const STATUS_CONTINUED = 'CONTINUED';

    protected $table = 'recommerce_trade_in_quick_quotes';

    protected $guarded = ['id'];

    protected $casts = [
        'specifications_json' => 'array',
        'condition_json' => 'array',
        'pricing_snapshot_json' => 'array',
        'customer_expected_amount' => 'decimal:4',
        'customer_expected_unknown' => 'boolean',
        'expected_resale_amount' => 'decimal:4',
        'estimated_low_amount' => 'decimal:4',
        'estimated_high_amount' => 'decimal:4',
        'expires_at' => 'datetime',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(\App\Contact::class, 'customer_contact_id');
    }

    public function variation(): BelongsTo
    {
        return $this->belongsTo(\App\Variation::class, 'variation_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(\App\User::class, 'created_by');
    }

    public function valuation(): BelongsTo
    {
        return $this->belongsTo(TradeInValuation::class, 'continued_to_valuation_id');
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }
}
