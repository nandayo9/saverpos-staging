<?php

namespace Modules\Recommerce\Entities;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Device extends Model
{
    protected $table = 'recommerce_devices';

    protected $guarded = ['id'];

    protected $casts = [
        'specifications_json' => 'array',
        'acquired_at' => 'datetime',
        'sold_at' => 'datetime',
        'retired_at' => 'datetime',
        'lock_version' => 'integer',
    ];

    public function identifiers(): HasMany
    {
        return $this->hasMany(DeviceIdentifier::class, 'device_id');
    }

    public function scanTokens(): HasMany
    {
        return $this->hasMany(ScanToken::class, 'device_id');
    }

    public function certification(): HasOne
    {
        return $this->hasOne(DeviceCertification::class, 'device_id');
    }

    public function purchaseAssignment(): HasOne
    {
        return $this->hasOne(DevicePurchaseAssignment::class, 'device_id');
    }

    public function acquisitions(): HasMany
    {
        return $this->hasMany(DeviceAcquisition::class, 'device_id');
    }

    public function ownershipPeriods(): HasMany
    {
        return $this->hasMany(OwnershipPeriod::class, 'device_id');
    }

    public function openOwnershipPeriod(): HasOne
    {
        return $this->hasOne(OwnershipPeriod::class, 'device_id')
            ->whereNotNull('open_period_key');
    }

    public function custodyPeriods(): HasMany
    {
        return $this->hasMany(CustodyPeriod::class, 'device_id');
    }

    public function openCustodyPeriod(): HasOne
    {
        return $this->hasOne(CustodyPeriod::class, 'device_id')
            ->whereNotNull('open_period_key');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(DeviceMovement::class, 'device_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(DeviceEvent::class, 'device_id');
    }

    /** Device Passport media is stored through UltimatePOS's existing media subsystem. */
    public function media()
    {
        return $this->morphMany(\App\Media::class, 'model');
    }

    public function repairJobs(): HasMany
    {
        return $this->hasMany(RepairJob::class, 'device_id');
    }

    public function saleDispositions(): HasMany
    {
        return $this->hasMany(DeviceSaleDisposition::class, 'device_id');
    }

    public function transferAssignments(): HasMany
    {
        return $this->hasMany(DeviceTransferAssignment::class, 'device_id');
    }

    public function returnDispositions(): HasMany
    {
        return $this->hasMany(DeviceReturnDisposition::class, 'device_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(\App\Product::class, 'product_id');
    }

    public function variation(): BelongsTo
    {
        return $this->belongsTo(\App\Variation::class, 'variation_id');
    }

    public function isStockParticipating(): bool
    {
        return in_array($this->stock_participation, ['ON_HAND', 'RESERVED', 'IN_TRANSFER'], true);
    }
}
