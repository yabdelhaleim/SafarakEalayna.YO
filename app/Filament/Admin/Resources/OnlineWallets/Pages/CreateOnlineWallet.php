<?php

namespace App\Filament\Admin\Resources\OnlineWallets\Pages;

use App\Enums\AccountType;
use App\Filament\Admin\Resources\OnlineWallets\OnlineWalletResource;
use Filament\Resources\Pages\CreateRecord;

class CreateOnlineWallet extends CreateRecord
{
    protected static string $resource = OnlineWalletResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['type'] = AccountType::Wallet->value;
        $data['module_type'] = 'online';

        return $data;
    }
}

