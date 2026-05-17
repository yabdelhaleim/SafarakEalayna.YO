<?php

namespace App\Filament\Admin\Resources\Transactions\Pages;

use App\Filament\Admin\Resources\Transactions\TransactionResource;
use Filament\Resources\Pages\ManageRecords;

class ManageTransactions extends ManageRecords
{
    protected static string $resource = TransactionResource::class;

    protected function getHeaderWidgets(): array
    {
        return [
            \App\Filament\Admin\Widgets\FinancialStatsWidget::class,
        ];
    }
}