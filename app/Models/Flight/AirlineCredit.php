<?php

namespace App\Models\Flight;

use App\Models\Customer;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'flight_carrier_id',
    'customer_id',
    'currency',
    'amount',
    'expiry_date',
    'flight_booking_id',
    'refund_request_id',
    'status',
])]
class AirlineCredit extends Model
{
    protected $fillable = [
        'flight_carrier_id',
        'customer_id',
        'currency',
        'amount',
        'expiry_date',
        'flight_booking_id',
        'refund_request_id',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'expiry_date' => 'date',
        ];
    }

    public function carrier(): BelongsTo
    {
        return $this->belongsTo(FlightCarrier::class, 'flight_carrier_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(FlightBooking::class, 'flight_booking_id');
    }

    public function refundRequest(): BelongsTo
    {
        return $this->belongsTo(RefundRequest::class, 'refund_request_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    public function scopeUsed(Builder $query): Builder
    {
        return $query->where('status', 'used');
    }

    public function scopeExpired(Builder $query): Builder
    {
        return $query->where('status', 'expired');
    }
}
