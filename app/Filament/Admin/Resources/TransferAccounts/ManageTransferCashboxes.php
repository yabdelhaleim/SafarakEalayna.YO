<?php

namespace App\Filament\Admin\Resources\TransferAccounts;

use Filament\Actions;
use Filament\Resources\Pages\ManageRecords;

class ManageTransferCashboxes extends ManageRecords
{
    protected static string $resource = TransferCashboxResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
