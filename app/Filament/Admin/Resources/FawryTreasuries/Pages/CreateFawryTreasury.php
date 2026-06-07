<?php

namespace App\Filament\Admin\Resources\FawryTreasuries\Pages;

use App\Filament\Admin\Resources\FawryTreasuries\FawryTreasuryResource;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;

class CreateFawryTreasury extends CreateRecord
{
    protected static string $resource = FawryTreasuryResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['module_type'] = 'fawry';
        $data['module'] = 'fawry';
        $data['type'] = \App\Enums\AccountType::Treasury->value;

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}