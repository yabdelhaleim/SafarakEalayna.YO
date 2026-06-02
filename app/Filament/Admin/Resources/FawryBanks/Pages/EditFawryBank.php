<?php

namespace App\Filament\Admin\Resources\FawryBanks\Pages;

use App\Filament\Admin\Resources\FawryBanks\FawryBankResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditFawryBank extends EditRecord
{
    protected static string $resource = FawryBankResource::class;

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