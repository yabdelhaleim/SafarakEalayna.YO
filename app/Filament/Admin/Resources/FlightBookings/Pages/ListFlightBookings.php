<?php

namespace App\Filament\Admin\Resources\FlightBookings\Pages;

use App\Filament\Admin\Concerns\HasSafarakFlightModulePageStyles;
use App\Filament\Admin\Resources\FlightBookings\FlightBookingResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListFlightBookings extends ListRecords
{
    use HasSafarakFlightModulePageStyles;

    protected static string $resource = FlightBookingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->modal(false),
        ];
    }
}
