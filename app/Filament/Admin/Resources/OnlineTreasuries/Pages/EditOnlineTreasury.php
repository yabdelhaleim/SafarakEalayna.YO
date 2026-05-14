<?php

namespace App\Filament\Admin\Resources\OnlineTreasuries\Pages;

use App\Enums\AccountType;
use App\Filament\Admin\Resources\OnlineTreasuries\OnlineTreasuryResource;
use Filament\Resources\Pages\EditRecord;

class EditOnlineTreasury extends EditRecord
{
    protected static string $resource = OnlineTreasuryResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['type'] = AccountType::Cashbox->value;
        $data['module_type'] = 'online';

        return $data;
    }
}

