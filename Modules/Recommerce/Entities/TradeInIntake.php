<?php

namespace Modules\Recommerce\Entities;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class TradeInIntake extends Model
{
    protected $table = 'recommerce_trade_in_intakes';
    protected $guarded = ['id'];
    protected $casts = [
        'submission_version' => 'integer',
        'specifications_json' => 'array',
        'declared_condition_json' => 'array',
        'indicative_snapshot_json' => 'array',
        'evidence_references_json' => 'array',
        'submitted_at' => 'datetime',
        'projection_version' => 'integer',
    ];

    public function valuation(): BelongsTo { return $this->belongsTo(TradeInValuation::class, 'valuation_id'); }
    public function offers(): HasMany { return $this->hasMany(TradeInApprovedOffer::class, 'intake_id')->orderBy('offer_version'); }
}
