<?php

namespace App\Filament\Admin\Resources\FawryMachines\Pages;

use App\Filament\Admin\Resources\FawryMachines\FawryMachineResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditFawryMachine extends EditRecord
{
    protected static string $resource = FawryMachineResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
