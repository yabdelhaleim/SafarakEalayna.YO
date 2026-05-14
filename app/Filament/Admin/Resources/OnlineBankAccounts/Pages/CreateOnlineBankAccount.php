<?php

namespace App\Filament\Admin\Resources\OnlineBankAccounts\Pages;

use App\Enums\AccountType;
use App\Filament\Admin\Resources\OnlineBankAccounts\OnlineBankAccountResource;
use Filament\Resources\Pages\CreateRecord;

class CreateOnlineBankAccount extends CreateRecord
{
    protected static string $resource = OnlineBankAccountResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['type'] = AccountType::Bank->value;
        $data['module_type'] = 'online';

        return $data;
    }
}

