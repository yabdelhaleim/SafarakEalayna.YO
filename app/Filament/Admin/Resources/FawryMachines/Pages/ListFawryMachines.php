<?php

namespace App\Filament\Admin\Resources\FawryMachines\Pages;

use App\Filament\Admin\Resources\FawryMachines\FawryMachineResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListFawryMachines extends ListRecords
{
    protected static string $resource = FawryMachineResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('إضافة جهاز شحن')
                ->modal(false),
        ];
    }
}
