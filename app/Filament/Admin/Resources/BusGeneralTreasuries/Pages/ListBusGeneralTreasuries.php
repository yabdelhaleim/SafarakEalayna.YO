<?php

namespace App\Filament\Admin\Resources\BusGeneralTreasuries\Pages;

use App\Filament\Admin\Resources\BusGeneralTreasuries\BusGeneralTreasuryResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListBusGeneralTreasuries extends ListRecords
{
    protected static string $resource = BusGeneralTreasuryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
