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
        return $data;
    }
}