<?php

namespace App\Filament\Admin\Resources\ExpenseAccounts\Pages;

use App\Enums\AccountType;
use App\Filament\Admin\Resources\ExpenseAccounts\ExpenseAccountResource;
use Filament\Resources\Pages\CreateRecord;

class CreateExpenseAccount extends CreateRecord
{
    protected static string $resource = ExpenseAccountResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['type'] = AccountType::Expense->value;

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
