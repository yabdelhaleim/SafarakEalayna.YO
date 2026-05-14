<?php

namespace App\Filament\Admin\Resources\VisaWallets\Pages;

use App\Filament\Admin\Resources\VisaWallets\VisaWalletResource;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;

class CreateVisaWallet extends CreateRecord
{
    protected static string $resource = VisaWalletResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['module_type'] = 'visas';
        return $data;
    }
}