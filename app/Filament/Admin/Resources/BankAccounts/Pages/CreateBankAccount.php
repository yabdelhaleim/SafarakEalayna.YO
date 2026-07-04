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
        // Honor the user's selection from the dropdown (Bank / Post).
        // Fall back to Bank only if nothing was submitted so legacy data paths still work.
        $data['type'] = $data['type'] ?? AccountType::Bank->value;

        // Guard: this resource only exposes Bank + Post; reject anything else explicitly.
        if (! in_array($data['type'], [AccountType::Bank->value, AccountType::Post->value], true)) {
            $data['type'] = AccountType::Bank->value;
        }

        // Lock module_type to flights when not provided.
        $data['module_type'] = $data['module_type'] ?? 'flights';

        return $data;
    }
}
