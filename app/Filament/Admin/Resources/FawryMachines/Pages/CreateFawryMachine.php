<?php

namespace App\Filament\Admin\Resources\FawryMachines\Pages;

use App\Filament\Admin\Resources\FawryMachines\FawryMachineResource;
use Filament\Resources\Pages\CreateRecord;

class CreateFawryMachine extends CreateRecord
{
    protected static string $resource = FawryMachineResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
