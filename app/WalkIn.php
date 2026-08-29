<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class WalkIn extends Model
{
    public const STATUS_OPEN = 'OPEN';
    public const STATUS_CONVERTED = 'CONVERTED';
    public const STATUS_NO_SALE = 'NO_SALE';

    protected $guarded = ['id'];

    protected $casts = [
        'arrived_at' => 'datetime',
        'converted_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    public function business()
    {
        return $this->belongsTo(Business::class);
    }

    public function location()
    {
        return $this->belongsTo(BusinessLocation::class);
    }

    public function recorder()
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function transaction()
    {
        return $this->belongsTo(Transaction::class);
    }

    public function closer()
    {
        return $this->belongsTo(User::class, 'closed_by');
    }
}
