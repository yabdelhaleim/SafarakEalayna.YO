<?php

namespace App\Filament\Admin\Resources\BusWallets\Pages;

use App\Filament\Admin\Resources\BusWallets\BusWalletResource;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;

class CreateBusWallet extends CreateRecord
{
    protected static string $resource = BusWalletResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['module_type'] = 'bus';
        return $data;
    }
}