<?php

namespace App\Filament\Admin\Resources\WalletAccounts\Pages;

use App\Enums\AccountType;
use App\Filament\Admin\Concerns\HasSafarakWalletModulePageStyles;
use App\Filament\Admin\Resources\WalletAccounts\WalletAccountResource;
use Filament\Resources\Pages\CreateRecord;

class CreateWalletAccount extends CreateRecord
{
    use HasSafarakWalletModulePageStyles;

    protected static string $resource = WalletAccountResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['type'] = AccountType::Wallet->value;

        return $data;
    }
}
