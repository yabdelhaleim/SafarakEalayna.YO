<?php

namespace App\Models\Flight;

use App\Models\Treasury;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'flight_booking_id',
    'refund_type',
    'original_currency',
    'original_amount',
    'cancellation_fee',
    'refund_amount',
    'refund_currency',
    'refund_exchange_rate',
    'base_currency_refund',
    'currency_difference',
    'destination',
    'treasury_id',
    'airline_credit_balance',
    'status',
    'notes',
    'processed_at',
    'created_by',
])]
class RefundRequest extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'flight_booking_id',
        'refund_type',
        'original_currency',
        'original_amount',
        'cancellation_fee',
        'refund_amount',
        'refund_currency',
        'refund_exchange_rate',
        'base_currency_refund',
        'currency_difference',
        'destination',
        'treasury_id',
        'airline_credit_balance',
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
            'currency_difference' => 'decimal:2',
            'airline_credit_balance' => 'decimal:2',
            'processed_at' => 'datetime',
        ];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(FlightBooking::class, 'flight_booking_id');
    }

    public function treasury(): BelongsTo
    {
        return $this->belongsTo(Treasury::class, 'treasury_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function airlineCredit(): HasOne
    {
        return $this->hasOne(AirlineCredit::class, 'refund_request_id');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', 'pending');
    }

    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('status', 'approved');
    }

    public function scopeProcessed(Builder $query): Builder
    {
        return $query->where('status', 'processed');
    }
}
