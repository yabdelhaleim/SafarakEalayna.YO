<?php

namespace App\Models\Flight;

use App\Enums\PassengerType;
use App\Models\Flight\FlightBooking;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'flight_booking_id',
    'passenger_type',
    'first_name',
    'last_name',
    'type',
    'date_of_birth',
    'relation_to_customer',
    'responsible_adult_id',
    'passport_number',
    'national_id',
    'baggage_allowance_kg',
])]
class FlightPassenger extends Model
{
    protected $table = 'passengers';

    protected function casts(): array
    {
        return [
            'type'         => PassengerType::class,
            'date_of_birth' => 'date',
        ];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(FlightBooking::class, 'flight_booking_id');
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(FlightTicket::class, 'passenger_id');
    }
}
