<?php

namespace App\Models\Flight;

use App\Models\Customer;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'transaction_id',
    'flight_booking_id',
    'customer_id',
    'amount',
    'status',
    'notes',
    'created_by',
    'allocated_at',
    'reversed_at',
    'reversed_by',
    'reversal_transaction_id',
])]
class FlightPaymentAllocation extends Model
{
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'allocated_at' => 'datetime',
            'reversed_at' => 'datetime',
        ];
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'transaction_id');
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(FlightBooking::class, 'flight_booking_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function reversedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reversed_by');
    }

    public function reversalTransaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'reversal_transaction_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    public function scopeReversed(Builder $query): Builder
    {
        return $query->where('status', 'reversed');
    }
}
