<?php

namespace App\Filament\Resources\Flight\AirlineCreditResource\Pages;

use App\Filament\Resources\Flight\AirlineCreditResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListAirlineCredits extends ListRecords
{
    protected static string $resource = AirlineCreditResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
