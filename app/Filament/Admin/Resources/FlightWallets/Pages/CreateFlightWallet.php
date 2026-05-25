<?php

namespace App\Filament\Admin\Resources\FlightWallets\Pages;

use App\Filament\Admin\Resources\FlightWallets\FlightWalletResource;
use Filament\Resources\Pages\CreateRecord;

class CreateFlightWallet extends CreateRecord
{
    protected static string $resource = FlightWalletResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['module_type'] = 'flights';
        return $data;
    }
}
