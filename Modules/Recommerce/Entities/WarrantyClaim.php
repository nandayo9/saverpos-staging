<?php

namespace Modules\Recommerce\Entities;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Recommerce\Services\WarrantyClaimService;

class WarrantyClaim extends Model
{
    protected $table = 'recommerce_warranty_claims';

    protected $guarded = ['id', 'claim_uuid', 'claim_number'];

    protected $casts = [
        'policy_snapshot_json' => 'array',
        'decision_evidence_json' => 'array',
    ];

    /** Read the immutable actual-cost authority stored by the POS job snapshot. */
    public function repairJob(): BelongsTo
    {
        return $this->belongsTo(RepairJob::class, 'repair_job_id');
    }

    /** Return the original sale context for a warranty claim. */
    public function sourceRepairJob(): BelongsTo
    {
        return $this->belongsTo(RepairJob::class, 'source_repair_job_id');
    }

    public function coreWarranty(): BelongsTo
    {
        return $this->belongsTo(\App\Warranty::class, 'warranty_id');
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class, 'device_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(WarrantyClaimLine::class, 'warranty_claim_id')->orderBy('sort_order')->orderBy('id');
    }
}
