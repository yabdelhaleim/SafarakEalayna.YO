<?php

namespace App\Filament\Admin\Resources\FawryOperationTypes\Pages;

use App\Filament\Admin\Resources\FawryOperationTypes\FawryOperationTypeResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditFawryOperationType extends EditRecord
{
    protected static string $resource = FawryOperationTypeResource::class;

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
