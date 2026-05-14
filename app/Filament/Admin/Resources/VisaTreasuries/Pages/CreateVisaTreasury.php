<?php

namespace App\Filament\Admin\Resources\VisaTreasuries\Pages;

use App\Filament\Admin\Resources\VisaTreasuries\VisaTreasuryResource;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;

class CreateVisaTreasury extends CreateRecord
{
    protected static string $resource = VisaTreasuryResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['module_type'] = 'visas';
        return $data;
    }
}