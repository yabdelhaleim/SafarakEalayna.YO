<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FlightSegment extends Model
{
    use HasFactory;

    protected $fillable = [
        'flight_booking_id',
        'from_airport',
        'to_airport',
        'departure_date',
        'departure_time',
        'arrival_time',
        'airline',
        'flight_number',
        'baggage',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'departure_date' => 'date',
            'departure_time' => 'datetime:H:i',
            'arrival_time' => 'datetime:H:i',
        ];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(FlightBooking::class, 'flight_booking_id');
    }
}
