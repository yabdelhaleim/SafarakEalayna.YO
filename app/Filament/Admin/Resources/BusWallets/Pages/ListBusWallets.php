<?php

namespace App\Filament\Admin\Resources\BusWallets\Pages;

use App\Filament\Admin\Resources\BusWallets\BusWalletResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListBusWallets extends ListRecords
{
    protected static string $resource = BusWalletResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}