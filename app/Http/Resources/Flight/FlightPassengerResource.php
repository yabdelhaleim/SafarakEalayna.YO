<?php

namespace App\Http\Resources\Flight;

use App\Enums\PassengerType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FlightPassengerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // The actual DB column is 'type' (not 'passenger_type')
        $rawType = $this->type ?? $this->passenger_type ?? 'adult';

        // Safely resolve the enum (tryFrom avoids ValueError on unexpected values)
        $type = $rawType instanceof PassengerType
            ? $rawType
            : (PassengerType::tryFrom($rawType) ?? PassengerType::ADULT);

        return [
            'id'                     => $this->id,
            'booking_id'             => $this->flight_booking_id,
            'passenger_type'         => $rawType,
            'passenger_type_label'   => $type->label(),
            'passenger_type_age_range' => $type->ageRange(),
            'name'                   => trim(($this->first_name ?? '') . ' ' . ($this->last_name ?? '')),
            'first_name'             => $this->first_name,
            'last_name'              => $this->last_name,
            'passport_number'        => $this->passport_number ?? null,
            'national_id'            => $this->national_id ?? null,
            'nationality'            => $this->nationality ?? null,
            'date_of_birth'          => $this->date_of_birth?->format('Y-m-d'),
            'baggage_allowance_kg'   => $this->baggage_allowance_kg !== null ? (float) $this->baggage_allowance_kg : 0,
        ];
    }
}
