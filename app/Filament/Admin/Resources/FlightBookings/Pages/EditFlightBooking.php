<?php

namespace App\Filament\Admin\Resources\FlightBookings\Pages;

use App\Filament\Admin\Concerns\HasSafarakFlightModulePageStyles;
use App\Filament\Admin\Resources\FlightBookings\FlightBookingResource;
use Filament\Resources\Pages\EditRecord;

class EditFlightBooking extends EditRecord
{
    use HasSafarakFlightModulePageStyles;

    protected static string $resource = FlightBookingResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        if (empty($data['origin']) && ! empty($data['from_airport'])) {
            $data['origin'] = $data['from_airport'];
        }
        if (empty($data['destination']) && ! empty($data['to_airport'])) {
            $data['destination'] = $data['to_airport'];
        }
        if (empty($data['airline']) && ! empty($data['airline_name'])) {
            $data['airline'] = $data['airline_name'];
        }

        return $data;
    }
}
