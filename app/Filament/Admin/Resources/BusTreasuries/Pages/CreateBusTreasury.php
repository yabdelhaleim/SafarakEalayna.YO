<?php

namespace App\Filament\Admin\Resources\BusTreasuries\Pages;

use App\Filament\Admin\Resources\BusTreasuries\BusTreasuryResource;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;

class CreateBusTreasury extends CreateRecord
{
    protected static string $resource = BusTreasuryResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['module_type'] = 'bus';
        return $data;
    }
}