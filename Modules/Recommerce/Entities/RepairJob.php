<?php

namespace Modules\Recommerce\Entities;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Recommerce\Support\RepairJobStateMachine;

class RepairJob extends Model
{
    protected $table = 'recommerce_repair_jobs';

    /** Raw public token is transient and is never persisted with the job. */
    public ?string $lookup_raw_token = null;

    protected $guarded = ['id', 'job_uuid', 'command_uuid', 'job_code', 'state', 'lock_version'];

    protected $casts = [
        'intake_snapshot_json' => 'array',
        'policy_snapshot_json' => 'array',
        'warranty_json' => 'array',
        'estimated_quote_amount' => 'decimal:4',
        'due_at' => 'date',
        'opened_at' => 'datetime',
        'closed_at' => 'datetime',
        'lock_version' => 'integer',
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class, 'device_id');
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(\App\Contact::class, 'contact_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(\App\User::class, 'assigned_to');
    }

    public function isCustomerRepair(): bool
    {
        return $this->job_type === RepairJobStateMachine::TYPE_CUSTOMER_REPAIR;
    }

    public function isInternalRefurbishment(): bool
    {
        return $this->job_type === RepairJobStateMachine::TYPE_INTERNAL_REFURBISHMENT;
    }

    public function diagnosticSessions(): HasMany
    {
        return $this->hasMany(DiagnosticSession::class, 'repair_job_id');
    }

    public function partReservations(): HasMany
    {
        return $this->hasMany(RepairPartReservation::class, 'repair_job_id');
    }

    public function partUsages(): HasMany
    {
        return $this->hasMany(RepairPartUsage::class, 'repair_job_id');
    }

    public function checklistItems(): HasMany
    {
        return $this->hasMany(RepairChecklistItem::class, 'repair_job_id')->orderBy('id');
    }

    public function stateTransitions(): HasMany
    {
        return $this->hasMany(RepairStateTransition::class, 'repair_job_id')->orderBy('occurred_at')->orderBy('id');
    }

    public function lookupTokens(): HasMany
    {
        return $this->hasMany(RepairLookupToken::class, 'repair_job_id');
    }

    public function quotes(): HasMany
    {
        return $this->hasMany(RepairQuote::class, 'repair_job_id');
    }

    public function parentJob(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_repair_job_id');
    }

    public function repeatJobs(): HasMany
    {
        return $this->hasMany(self::class, 'parent_repair_job_id')->orderBy('id');
    }
}
