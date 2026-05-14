<?php

namespace App\Models\Flight;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'airline_account_id',
    'flight_carrier_id',
    'flight_booking_id',
    'type',
    'amount',
    'balance_before',
    'balance_after',
    'description',
    'created_by',
])]
class AirlineTransaction extends Model
{
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'balance_before' => 'decimal:2',
            'balance_after' => 'decimal:2',
            'type' => 'string', // credit, debit, refund
        ];
    }

    public function airlineAccount(): BelongsTo
    {
        return $this->belongsTo(AirlineAccount::class);
    }

    public function flightCarrier(): BelongsTo
    {
        return $this->belongsTo(FlightCarrier::class);
    }

    public function flightBooking(): BelongsTo
    {
        return $this->belongsTo(FlightBooking::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeCredits($query)
    {
        return $query->where('type', 'credit');
    }

    public function scopeDebits($query)
    {
        return $query->where('type', 'debit');
    }

    public function scopeRefunds($query)
    {
        return $query->where('type', 'refund');
    }

    public function scopeForAccount($query, int $accountId)
    {
        return $query->where('airline_account_id', $accountId);
    }
}
