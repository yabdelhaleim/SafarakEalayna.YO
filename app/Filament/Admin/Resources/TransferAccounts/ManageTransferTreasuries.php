<?php

namespace App\Filament\Admin\Resources\TransferAccounts;

use Filament\Actions;
use Filament\Resources\Pages\ManageRecords;

class ManageTransferTreasuries extends ManageRecords
{
    protected static string $resource = TransferTreasuryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
