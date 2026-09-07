<?php

namespace Modules\Recommerce\Entities;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class TradeInPhotoAiReview extends Model
{
    protected $table = 'recommerce_trade_in_photo_ai_reviews';
    protected $guarded = ['id'];
    protected $casts = ['technician_findings_json' => 'array', 'disagreements_json' => 'array', 'reviewed_at' => 'datetime'];

    public function analysis(): BelongsTo { return $this->belongsTo(TradeInPhotoAiAnalysis::class, 'analysis_id'); }
}
