<?php

namespace App\Http\Resources\Flight;

use App\Enums\FlightClass;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FlightSegmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $class = $this->flight_class instanceof FlightClass
            ? $this->flight_class
            : FlightClass::from($this->flight_class ?? 'economy');

        return [
            'id' => $this->id,
            'booking_id' => $this->booking_id,
            'airline' => $this->airline ?? $this->airline_name,
            'airline_name' => $this->airline ?? $this->airline_name,
            'flight_number' => $this->flight_number,
            'from_airport' => $this->from_airport,
            'from' => $this->from_airport,
            'to_airport' => $this->to_airport,
            'to' => $this->to_airport,
            'route' => $this->from_airport && $this->to_airport
                ? "{$this->from_airport} → {$this->to_airport}"
                : null,
            'departure_date' => $this->departure_date?->format('Y-m-d'),
            'departure_time' => $this->departure_time?->format('H:i'),
            'arrival_time' => $this->arrival_time?->format('H:i'),
            'baggage_allowance' => $this->baggage,
            'baggage' => $this->baggage,
            'flight_class' => $this->flight_class,
            'flight_class_label' => $class->label(),
            'duration_minutes' => $this->duration_minutes,
            'duration_formatted' => $this->duration,
            'is_stop' => $this->is_stop,
            'stop_duration_minutes' => $this->stop_duration_minutes,
            'notes' => $this->notes,
            'created_at' => $this->created_at?->format('Y-m-d H:i:s'),
            'updated_at' => $this->updated_at?->format('Y-m-d H:i:s'),
        ];
    }
}
