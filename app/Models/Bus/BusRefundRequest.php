<?php

namespace App\Models\Bus;

use App\Models\Treasury;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BusRefundRequest extends Model
{
    protected $fillable = [
        'bus_booking_id',
        'company_id',
        'refund_type',
        'original_currency',
        'original_amount',
        'cancellation_fee',
        'refund_amount',
        'refund_currency',
        'refund_exchange_rate',
        'base_currency_refund',
        'destination',
        'treasury_id',
        'status',
        'notes',
        'processed_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'original_amount' => 'decimal:2',
            'cancellation_fee' => 'decimal:2',
            'refund_amount' => 'decimal:2',
            'refund_exchange_rate' => 'decimal:6',
            'base_currency_refund' => 'decimal:2',
            'processed_at' => 'datetime',
        ];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(BusBooking::class, 'bus_booking_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(BusCompany::class, 'company_id');
    }

    public function treasury(): BelongsTo
    {
        return $this->belongsTo(Treasury::class, 'treasury_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeProcessed($query)
    {
        return $query->where('status', 'processed');
    }
}
