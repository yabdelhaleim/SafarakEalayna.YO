<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Passenger extends Model
{
    use HasFactory;

    protected $fillable = [
        'flight_booking_id',
        'first_name',
        'last_name',
        'type',
        'date_of_birth',
        'relation_to_customer',
        'responsible_adult_id',
    ];

    protected function casts(): array
    {
        return [
            'type' => \App\Enums\PassengerType::class,
            'date_of_birth' => 'date',
        ];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(FlightBooking::class, 'flight_booking_id');
    }

    public function responsibleAdult(): BelongsTo
    {
        return $this->belongsTo(Passenger::class, 'responsible_adult_id');
    }
}
