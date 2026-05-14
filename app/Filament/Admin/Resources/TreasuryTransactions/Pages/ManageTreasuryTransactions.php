<?php

namespace App\Filament\Admin\Resources\TreasuryTransactions\Pages;

use App\Filament\Admin\Resources\TreasuryTransactions\TreasuryTransactionResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageTreasuryTransactions extends ManageRecords
{
    protected static string $resource = TreasuryTransactionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
