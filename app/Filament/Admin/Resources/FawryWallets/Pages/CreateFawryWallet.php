<?php

namespace App\Filament\Admin\Resources\FawryWallets\Pages;

use App\Filament\Admin\Resources\FawryWallets\FawryWalletResource;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;

class CreateFawryWallet extends CreateRecord
{
    protected static string $resource = FawryWalletResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['module_type'] = 'fawry';
        return $data;
    }
}