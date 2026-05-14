<?php

namespace App\Filament\Admin\Resources\OnlineTreasuries\Pages;

use App\Enums\AccountType;
use App\Filament\Admin\Resources\OnlineTreasuries\OnlineTreasuryResource;
use Filament\Resources\Pages\CreateRecord;

class CreateOnlineTreasury extends CreateRecord
{
    protected static string $resource = OnlineTreasuryResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['type'] = AccountType::Cashbox->value;
        $data['module_type'] = 'online';

        return $data;
    }
}

