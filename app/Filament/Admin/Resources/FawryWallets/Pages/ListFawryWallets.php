<?php

namespace App\Filament\Admin\Resources\FawryWallets\Pages;

use App\Filament\Admin\Resources\FawryWallets\FawryWalletResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListFawryWallets extends ListRecords
{
    protected static string $resource = FawryWalletResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}