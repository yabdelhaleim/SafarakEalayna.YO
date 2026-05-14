<?php

namespace App\Filament\Admin\Resources\OnlineWallets\Pages;

use App\Enums\AccountType;
use App\Filament\Admin\Resources\OnlineWallets\OnlineWalletResource;
use Filament\Resources\Pages\EditRecord;

class EditOnlineWallet extends EditRecord
{
    protected static string $resource = OnlineWalletResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['type'] = AccountType::Wallet->value;
        $data['module_type'] = 'online';

        return $data;
    }
}

