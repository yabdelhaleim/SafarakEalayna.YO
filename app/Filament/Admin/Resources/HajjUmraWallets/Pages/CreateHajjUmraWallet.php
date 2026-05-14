<?php

namespace App\Filament\Admin\Resources\HajjUmraWallets\Pages;

use App\Filament\Admin\Resources\HajjUmraWallets\HajjUmraWalletResource;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;

class CreateHajjUmraWallet extends CreateRecord
{
    protected static string $resource = HajjUmraWalletResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['module_type'] = 'hajj_umra';
        return $data;
    }
}