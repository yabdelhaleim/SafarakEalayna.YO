<?php

namespace App\Filament\Admin\Resources\FlightWallets\Pages;

use App\Filament\Admin\Resources\FlightWallets\FlightWalletResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditFlightWallet extends EditRecord
{
    protected static string $resource = FlightWalletResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\ViewAction::make(),
            Actions\DeleteAction::make(),
        ];
    }
}
