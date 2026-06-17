<?php

namespace App\Filament\Admin\Resources\ExpenseAccounts\Pages;

use App\Enums\AccountType;
use App\Filament\Admin\Resources\ExpenseAccounts\ExpenseAccountResource;
use Filament\Resources\Pages\EditRecord;

class EditExpenseAccount extends EditRecord
{
    protected static string $resource = ExpenseAccountResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['type'] = AccountType::Expense->value;

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
