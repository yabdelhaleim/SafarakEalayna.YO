<?php

namespace App\Filament\Admin\Resources\FlightGeneralTreasuries\Pages;

use App\Filament\Admin\Concerns\HasSafarakFlightModulePageStyles;
use App\Filament\Admin\Resources\FlightGeneralTreasuries\FlightGeneralTreasuryResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListFlightGeneralTreasuries extends ListRecords
{
    use HasSafarakFlightModulePageStyles;

    protected static string $resource = FlightGeneralTreasuryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->modal(false),
        ];
    }
}

