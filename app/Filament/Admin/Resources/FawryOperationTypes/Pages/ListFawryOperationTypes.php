<?php

namespace App\Filament\Admin\Resources\FawryOperationTypes\Pages;

use App\Filament\Admin\Resources\FawryOperationTypes\FawryOperationTypeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListFawryOperationTypes extends ListRecords
{
    protected static string $resource = FawryOperationTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('إضافة نوع')
                ->modal(false),
        ];
    }
}
