<?php

namespace Modules\Recommerce\Entities;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class TradeInValuation extends Model
{
    public const STATUS_READY_TO_ACCEPT = 'READY_TO_ACCEPT';
    public const STATUS_PENDING_APPROVAL = 'PENDING_APPROVAL';
    public const STATUS_APPROVED = 'APPROVED';
    public const STATUS_ACCEPTED = 'ACCEPTED';
    public const STATUS_REJECTED = 'REJECTED';
    public const STATUS_REVERSED = 'REVERSED';

    protected $table = 'recommerce_trade_in_valuations';

    protected $guarded = ['id'];

    protected $casts = [
        'inspection_json' => 'array',
        'pricing_snapshot_json' => 'array',
        'market_low_amount' => 'decimal:4',
        'market_high_amount' => 'decimal:4',
        'market_reference_amount' => 'decimal:4',
        'expected_resale_amount' => 'decimal:4',
        'expected_refurbishment_amount' => 'decimal:4',
        'opening_offer_amount' => 'decimal:4',
        'target_acquisition_amount' => 'decimal:4',
        'negotiation_ceiling_amount' => 'decimal:4',
        'economic_ceiling_amount' => 'decimal:4',
        'staff_proposed_amount' => 'decimal:4',
        'customer_requested_amount' => 'decimal:4',
        'final_acquisition_amount' => 'decimal:4',
        'approval_required' => 'boolean',
        'approved_at' => 'datetime',
        'accepted_at' => 'datetime',
        'rejected_at' => 'datetime',
        'lock_version' => 'integer',
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class, 'device_id');
    }

    public function ruleSet(): BelongsTo
    {
        return $this->belongsTo(TradeInRuleSet::class, 'rule_set_id');
    }

    public function marketEvidence(): HasMany
    {
        return $this->hasMany(TradeInMarketEvidence::class, 'valuation_id')->orderBy('observed_at')->orderBy('id');
    }

    public function acquisition(): HasOne
    {
        return $this->hasOne(DeviceAcquisition::class, 'trade_in_valuation_id');
    }
}
