<?php

namespace App\Filament\Admin\Resources\BusBanks\Pages;

use App\Filament\Admin\Resources\BusBanks\BusBankResource;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;

class CreateBusBank extends CreateRecord
{
    protected static string $resource = BusBankResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['module_type'] = 'bus';
        return $data;
    }
}