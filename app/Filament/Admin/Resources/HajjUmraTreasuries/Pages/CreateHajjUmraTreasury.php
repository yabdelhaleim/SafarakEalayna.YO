<?php

namespace App\Filament\Admin\Resources\HajjUmraTreasuries\Pages;

use App\Filament\Admin\Resources\HajjUmraTreasuries\HajjUmraTreasuryResource;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;

class CreateHajjUmraTreasury extends CreateRecord
{
    protected static string $resource = HajjUmraTreasuryResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['module_type'] = 'hajj_umra';
        return $data;
    }
}