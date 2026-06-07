<?php

namespace App\Filament\Admin\Resources\FawryBanks\Pages;

use App\Filament\Admin\Resources\FawryBanks\FawryBankResource;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;

class CreateFawryBank extends CreateRecord
{
    protected static string $resource = FawryBankResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['module_type'] = 'fawry';
        $data['module'] = 'fawry';
        $data['type'] = \App\Enums\AccountType::Bank->value;

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}