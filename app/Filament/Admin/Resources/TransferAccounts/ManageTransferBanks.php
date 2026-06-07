<?php

namespace App\Filament\Admin\Resources\TransferAccounts;

use Filament\Actions;
use Filament\Resources\Pages\ManageRecords;

class ManageTransferBanks extends ManageRecords
{
    protected static string $resource = TransferBankResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->mutateFormDataUsing(function (array $data): array {
                    $data['module_type'] = 'wallet_transfer';

                    return $data;
                }),
        ];
    }
}
