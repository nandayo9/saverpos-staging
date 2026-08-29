<?php

namespace Modules\Recommerce\Entities;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RepairQuote extends Model
{
    public const STATUS_DRAFT = 'DRAFT';
    public const STATUS_SENT = 'SENT';
    public const STATUS_APPROVED = 'APPROVED';
    public const STATUS_DECLINED = 'DECLINED';
    public const STATUS_EXPIRED = 'EXPIRED';
    public const STATUS_SUPERSEDED = 'SUPERSEDED';

    public const LINE_TYPE_PART = 'PART';
    public const LINE_TYPE_SERVICE = 'SERVICE';
    public const LINE_TYPE_FEE = 'FEE';

    protected $table = 'recommerce_repair_quotes';

    /**
     * Quote identity and lifecycle fields are controlled by the quote service.
     * Amounts stay guarded too: they are written only from the immutable
     * persisted line snapshot at creation or explicit draft replacement.
     */
    protected $guarded = [
        'id',
        'quote_uuid',
        'command_uuid',
        'version_number',
        'status',
        'subtotal_amount',
        'tax_amount',
        'total_amount',
        'sent_at',
        'sent_channel',
        'sent_by',
        'decided_at',
        'decided_by',
        'decision_evidence_json',
        'superseded_by_quote_id',
    ];

    protected $casts = [
        'version_number' => 'integer',
        'tax_assumptions_json' => 'array',
        'terms_json' => 'array',
        'subtotal_amount' => 'decimal:4',
        'tax_amount' => 'decimal:4',
        'total_amount' => 'decimal:4',
        'decision_evidence_json' => 'array',
        'expires_at' => 'datetime',
        'sent_at' => 'datetime',
        'decided_at' => 'datetime',
    ];

    public function job(): BelongsTo
    {
        return $this->belongsTo(RepairJob::class, 'repair_job_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(RepairQuoteLine::class, 'quote_id')->orderBy('sort_order')->orderBy('id');
    }

    public function succeededBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'superseded_by_quote_id');
    }

    public function isImmutable(): bool
    {
        return $this->status !== self::STATUS_DRAFT;
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /** Sent, non-expired, and not-yet-decided quote is the only approvable state. */
    public function isDecidable(): bool
    {
        return $this->status === self::STATUS_SENT && ! $this->isExpired();
    }
}
