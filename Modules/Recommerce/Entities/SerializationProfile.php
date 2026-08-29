<?php

namespace Modules\Recommerce\Entities;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SerializationProfile extends Model
{
    protected $table = 'recommerce_serialization_profiles';

    protected $guarded = ['id'];

    protected $casts = [
        'version' => 'integer',
        'effective_at' => 'datetime',
    ];

    public function balances(): HasMany
    {
        return $this->hasMany(LegacyStockBalance::class, 'serialization_profile_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(\App\Product::class, 'product_id');
    }

    public function variation(): BelongsTo
    {
        return $this->belongsTo(\App\Variation::class, 'variation_id');
    }
}
