<?php

namespace App\Filament\Admin\Resources\FlightSystems\Pages;

use App\Filament\Admin\Concerns\HasSafarakFlightModulePageStyles;
use App\Filament\Admin\Resources\FlightSystems\FlightSystemResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListFlightSystems extends ListRecords
{
    use HasSafarakFlightModulePageStyles;

    protected static string $resource = FlightSystemResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->modal(false),
        ];
    }
}
