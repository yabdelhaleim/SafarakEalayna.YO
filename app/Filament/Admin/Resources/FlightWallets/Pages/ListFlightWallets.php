<?php

namespace App\Filament\Admin\Resources\FlightWallets\Pages;

use App\Filament\Admin\Resources\FlightWallets\FlightWalletResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListFlightWallets extends ListRecords
{
    protected static string $resource = FlightWalletResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
