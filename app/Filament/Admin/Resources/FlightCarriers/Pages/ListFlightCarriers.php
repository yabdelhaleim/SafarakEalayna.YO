<?php

namespace App\Filament\Admin\Resources\FlightCarriers\Pages;

use App\Filament\Admin\Concerns\HasSafarakFlightModulePageStyles;
use App\Filament\Admin\Resources\FlightCarriers\FlightCarrierResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListFlightCarriers extends ListRecords
{
    use HasSafarakFlightModulePageStyles;

    protected static string $resource = FlightCarrierResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->modal(false),
        ];
    }
}
