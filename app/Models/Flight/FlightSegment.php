<?php

namespace App\Models\Flight;

use App\Enums\FlightClass;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'flight_booking_id',
    'from_airport',
    'from_airport_id',
    'to_airport',
    'to_airport_id',
    'departure_date',
    'departure_time',
    'arrival_time',
    'airline',
    'flight_number',
    'baggage',
    'baggage_allowance',
    'flight_class',
    'duration_minutes',
    'is_stop',
    'stop_duration_minutes',
    'notes',
    'sort_order',
])]
class FlightSegment extends Model
{
    protected function casts(): array
    {
        return [
            'departure_date' => 'date',
            'departure_time' => 'datetime',
            'arrival_time' => 'datetime',
        ];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(FlightBooking::class, 'flight_booking_id');
    }

    public function fromAirport(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Airport::class, 'from_airport_id');
    }

    public function toAirport(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Airport::class, 'to_airport_id');
    }

    public function getDurationAttribute(): ?string
    {
        if (!$this->duration_minutes) {
            return null;
        }

        $hours = floor($this->duration_minutes / 60);
        $minutes = $this->duration_minutes % 60;

        return $hours > 0
            ? "{$hours}h {$minutes}m"
            : "{$minutes}m";
    }

    public function getRouteAttribute(): string
    {
        $from = $this->from_airport ?? '???';
        $to = $this->to_airport ?? '???';
        return "{$from} → {$to}";
    }
}
