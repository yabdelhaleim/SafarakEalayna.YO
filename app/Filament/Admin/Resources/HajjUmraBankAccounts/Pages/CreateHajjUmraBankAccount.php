<?php

namespace App\Filament\Admin\Resources\HajjUmraBankAccounts\Pages;

use App\Enums\AccountType;
use App\Filament\Admin\Resources\HajjUmraBankAccounts\HajjUmraBankAccountResource;
use Filament\Resources\Pages\CreateRecord;

class CreateHajjUmraBankAccount extends CreateRecord
{
    protected static string $resource = HajjUmraBankAccountResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['type'] = AccountType::Bank->value;
        $data['module_type'] = 'hajj_umra';

        return $data;
    }
}

