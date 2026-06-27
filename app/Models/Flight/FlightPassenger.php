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
    'first_name_en',
    'last_name',
    'last_name_en',
    'type',
    'date_of_birth',
    'birth_date',
    'relation_to_customer',
    'responsible_adult_id',
    'passport_number',
    'national_id',
    'baggage_allowance_kg',
    'traveled_at',
])]
class FlightPassenger extends Model
{
    protected $table = 'passengers';

    protected function casts(): array
    {
        return [
            'type'          => PassengerType::class,
            'date_of_birth' => 'date',
            'birth_date'    => 'date',
            'traveled_at'   => 'datetime',
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

    public function responsibleAdult(): BelongsTo
    {
        return $this->belongsTo(FlightPassenger::class, 'responsible_adult_id');
    }
}
