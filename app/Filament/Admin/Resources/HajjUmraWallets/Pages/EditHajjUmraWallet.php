<?php

namespace App\Filament\Admin\Resources\HajjUmraWallets\Pages;

use App\Filament\Admin\Resources\HajjUmraWallets\HajjUmraWalletResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditHajjUmraWallet extends EditRecord
{
    protected static string $resource = HajjUmraWalletResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\ViewAction::make(),
            Actions\DeleteAction::make(),
        ];
    }
}