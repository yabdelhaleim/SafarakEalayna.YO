<?php

namespace App\Filament\Admin\Resources\FlightGroups\Pages;

use App\Filament\Admin\Concerns\HasSafarakFlightModulePageStyles;
use App\Filament\Admin\Resources\FlightGroups\FlightGroupResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListFlightGroups extends ListRecords
{
    use HasSafarakFlightModulePageStyles;

    protected static string $resource = FlightGroupResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->modal(false),
        ];
    }
}
