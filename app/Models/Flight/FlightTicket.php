<?php

namespace App\Models\Flight;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'flight_booking_id',
    'passenger_id',
    'ticket_number',
    'status',
])]
class FlightTicket extends Model
{
    public function booking(): BelongsTo
    {
        return $this->belongsTo(FlightBooking::class, 'flight_booking_id');
    }

    public function passenger(): BelongsTo
    {
        return $this->belongsTo(FlightPassenger::class, 'passenger_id');
    }
}
