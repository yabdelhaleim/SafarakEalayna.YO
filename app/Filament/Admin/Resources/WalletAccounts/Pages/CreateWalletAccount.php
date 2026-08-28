<?php

namespace App\Filament\Admin\Resources\WalletAccounts\Pages;

use App\Enums\AccountType;
use App\Enums\WalletProvider;
use App\Filament\Admin\Concerns\HasSafarakWalletModulePageStyles;
use App\Filament\Admin\Resources\WalletAccounts\WalletAccountResource;
use App\Support\Finance\AccountModuleDivision;
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

    /**
     * Pre-fill wallet_provider and module_type from URL query string
     * when arriving from a shortcut link (e.g. WalletCreate.vue).
     *
     * Validates against the canonical enum/options lists so a tampered URL
     * cannot inject arbitrary values.
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $provider = request()->query('wallet_provider');
        if (is_string($provider) && array_key_exists($provider, WalletProvider::optionsForSelect())) {
            $data['wallet_provider'] = $provider;
        }

        $moduleType = request()->query('module_type');
        if (is_string($moduleType) && in_array($moduleType, array_keys(AccountModuleDivision::moduleTypeOptions()), true)) {
            $data['module_type'] = $moduleType;
        }

        return $data;
    }
}
