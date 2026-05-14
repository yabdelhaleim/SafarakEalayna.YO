<?php

namespace App\Filament\Admin\Resources\FlightBookings\Pages;

use App\Enums\FlightBookingStatus;
use App\Filament\Admin\Concerns\HasSafarakFlightModulePageStyles;
use App\Filament\Admin\Resources\FlightBookings\FlightBookingResource;
use Filament\Resources\Pages\CreateRecord;

class CreateFlightBooking extends CreateRecord
{
    use HasSafarakFlightModulePageStyles;

    protected static string $resource = FlightBookingResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if (empty($data['booking_number'])) {
            $data['booking_number'] = 'FLT-'.now()->format('Ymd').'-'.strtoupper(substr(md5(uniqid((string) mt_rand(), true)), 0, 8));
        }
        if (empty($data['booking_reference'])) {
            $data['booking_reference'] = $data['booking_number'];
        }
        if (empty($data['origin']) && ! empty($data['from_airport'])) {
            $data['origin'] = $data['from_airport'];
        }
        if (empty($data['destination']) && ! empty($data['to_airport'])) {
            $data['destination'] = $data['to_airport'];
        }
        if (empty($data['airline']) && ! empty($data['airline_name'])) {
            $data['airline'] = $data['airline_name'];
        }
        $data['status'] = $data['status'] ?? FlightBookingStatus::PENDING->value;
        $data['created_by'] = auth()->id();

        return $data;
    }
}
