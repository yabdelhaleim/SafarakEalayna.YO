<?php

namespace App\Filament\Admin\Resources\FawryCashboxes\Pages;

use App\Filament\Admin\Resources\FawryCashboxes\FawryCashboxResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditFawryCashbox extends EditRecord
{
    protected static string $resource = FawryCashboxResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\ViewAction::make(),
            Actions\DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['module_type'] = 'fawry';
        return $data;
    }
}