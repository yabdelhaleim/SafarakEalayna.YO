<?php

namespace App\Filament\Admin\Resources\TransferAccounts;

use Filament\Actions;
use Filament\Resources\Pages\ManageRecords;

class ManageTransferWallets extends ManageRecords
{
    protected static string $resource = TransferWalletResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
