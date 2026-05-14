<?php

namespace App\Filament\Admin\Resources\OnlineGeneralTreasuries\Pages;

use App\Enums\AccountType;
use App\Filament\Admin\Resources\OnlineGeneralTreasuries\OnlineGeneralTreasuryResource;
use Filament\Resources\Pages\EditRecord;

class EditOnlineGeneralTreasury extends EditRecord
{
    protected static string $resource = OnlineGeneralTreasuryResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['type'] = AccountType::Treasury->value;
        $data['module_type'] = 'online';

        return $data;
    }
}

