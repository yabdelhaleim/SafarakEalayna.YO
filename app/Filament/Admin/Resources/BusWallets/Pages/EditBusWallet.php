<?php

namespace App\Filament\Admin\Resources\BusWallets\Pages;

use App\Filament\Admin\Resources\BusWallets\BusWalletResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditBusWallet extends EditRecord
{
    protected static string $resource = BusWalletResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\ViewAction::make(),
            Actions\DeleteAction::make(),
        ];
    }
}