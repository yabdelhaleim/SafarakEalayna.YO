<?php

namespace App\Filament\Admin\Resources\BankAccounts\Pages;

use App\Enums\AccountType;
use App\Filament\Admin\Concerns\HasSafarakFlightModulePageStyles;
use App\Filament\Admin\Resources\BankAccounts\BankAccountResource;
use Filament\Resources\Pages\CreateRecord;

class CreateBankAccount extends CreateRecord
{
    use HasSafarakFlightModulePageStyles;

    protected static string $resource = BankAccountResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['type'] = AccountType::Bank->value;

        return $data;
    }
}
