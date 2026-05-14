<?php

namespace App\Filament\Admin\Resources\HajjUmraWallets\Pages;

use App\Filament\Admin\Resources\HajjUmraWallets\HajjUmraWalletResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListHajjUmraWallets extends ListRecords
{
    protected static string $resource = HajjUmraWalletResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}