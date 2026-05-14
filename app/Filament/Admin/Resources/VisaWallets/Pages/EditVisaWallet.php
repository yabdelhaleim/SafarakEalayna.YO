<?php

namespace App\Filament\Admin\Resources\VisaWallets\Pages;

use App\Filament\Admin\Resources\VisaWallets\VisaWalletResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditVisaWallet extends EditRecord
{
    protected static string $resource = VisaWalletResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\ViewAction::make(),
            Actions\DeleteAction::make(),
        ];
    }
}