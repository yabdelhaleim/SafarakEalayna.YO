<?php

namespace App\Filament\Admin\Resources\FlightTreasuries\Pages;

use App\Filament\Admin\Resources\FlightTreasuries\FlightTreasuryResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListFlightTreasuries extends ListRecords
{
    protected static string $resource = FlightTreasuryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
