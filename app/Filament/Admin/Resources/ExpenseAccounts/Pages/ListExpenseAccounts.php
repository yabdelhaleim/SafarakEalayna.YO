<?php

namespace App\Filament\Admin\Resources\ExpenseAccounts\Pages;

use App\Filament\Admin\Resources\ExpenseAccounts\ExpenseAccountResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListExpenseAccounts extends ListRecords
{
    protected static string $resource = ExpenseAccountResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->modal(false),
        ];
    }
}
