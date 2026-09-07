<?php

namespace Modules\Recommerce\Entities;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

final class TradeInPhotoAiAnalysis extends Model
{
    protected $table = 'recommerce_trade_in_photo_ai_analyses';
    protected $guarded = ['id'];
    protected $casts = [
        'analysis_version' => 'integer', 'evidence_ids_json' => 'array', 'result_json' => 'array',
        'customer_confirmation_json' => 'array', 'confirmed_condition_json' => 'array',
        'catalogue_candidates_json' => 'array', 'identity_resolution_json' => 'array',
        'overall_confidence' => 'float',
    ];

    public function intake(): BelongsTo { return $this->belongsTo(TradeInIntake::class, 'intake_id'); }
    public function review(): HasOne { return $this->hasOne(TradeInPhotoAiReview::class, 'analysis_id'); }
}
