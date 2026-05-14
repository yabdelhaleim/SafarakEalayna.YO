<?php

namespace App\Http\Resources\Flight;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FlightTicketResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'ticket_number' => $this->ticket_number,
            'status' => $this->status,
            'passenger_id' => $this->passenger_id,
            'flight_booking_id' => $this->flight_booking_id,
            'created_at' => $this->created_at?->format('Y-m-d H:i:s'),
        ];
    }
}
