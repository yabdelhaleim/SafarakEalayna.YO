<?php

namespace App\Filament\Admin\Resources\WalletTypes\Pages;

use App\Filament\Admin\Resources\WalletTypes\WalletTypeResource;
use Filament\Resources\Pages\CreateRecord;

class CreateWalletType extends CreateRecord
{
    protected static string $resource = WalletTypeResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
