<?php

namespace App\Filament\Admin\Resources\WalletAccounts\Pages;

use App\Filament\Admin\Concerns\HasSafarakWalletModulePageStyles;
use App\Filament\Admin\Resources\WalletAccounts\WalletAccountResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListWalletAccounts extends ListRecords
{
    use HasSafarakWalletModulePageStyles;

    protected static string $resource = WalletAccountResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->modal(false),
        ];
    }
}
