<?php

namespace App\Filament\Admin\Resources\FawryOperationTypes\Pages;

use App\Filament\Admin\Resources\FawryOperationTypes\FawryOperationTypeResource;
use Filament\Resources\Pages\CreateRecord;

class CreateFawryOperationType extends CreateRecord
{
    protected static string $resource = FawryOperationTypeResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
