<?php

namespace App\Filament\Admin\Resources\FawryWallets\Pages;

use App\Filament\Admin\Resources\FawryWallets\FawryWalletResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditFawryWallet extends EditRecord
{
    protected static string $resource = FawryWalletResource::class;

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