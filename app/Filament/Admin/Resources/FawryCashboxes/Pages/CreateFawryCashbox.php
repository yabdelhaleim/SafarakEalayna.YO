<?php

namespace App\Filament\Admin\Resources\FawryCashboxes\Pages;

use App\Filament\Admin\Resources\FawryCashboxes\FawryCashboxResource;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;

class CreateFawryCashbox extends CreateRecord
{
    protected static string $resource = FawryCashboxResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['module_type'] = 'fawry';
        $data['module'] = 'fawry';
        $data['type'] = \App\Enums\AccountType::Cashbox->value;

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}