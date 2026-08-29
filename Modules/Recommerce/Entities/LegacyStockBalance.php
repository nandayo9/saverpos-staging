<?php

namespace Modules\Recommerce\Entities;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LegacyStockBalance extends Model
{
    protected $table = 'recommerce_legacy_stock_balances';

    protected $guarded = ['id'];

    protected $casts = [
        'legacy_unserialized_qty' => 'decimal:4',
        'approved_at' => 'datetime',
    ];

    public function profile(): BelongsTo
    {
        return $this->belongsTo(SerializationProfile::class, 'serialization_profile_id');
    }
}
