<?php

namespace App\Filament\Admin\Resources\FawryTreasuries\Pages;

use App\Filament\Admin\Resources\FawryTreasuries\FawryTreasuryResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditFawryTreasury extends EditRecord
{
    protected static string $resource = FawryTreasuryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['module_type'] = 'fawry';
        $data['module'] = 'fawry';

        return $data;
    }
}