<?php

namespace App\Filament\Admin\Resources\OnlineGeneralTreasuries\Pages;

use App\Enums\AccountType;
use App\Filament\Admin\Resources\OnlineGeneralTreasuries\OnlineGeneralTreasuryResource;
use Filament\Resources\Pages\CreateRecord;

class CreateOnlineGeneralTreasury extends CreateRecord
{
    protected static string $resource = OnlineGeneralTreasuryResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['type'] = AccountType::Treasury->value;
        $data['module_type'] = 'online';

        return $data;
    }
}

