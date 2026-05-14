<?php

namespace App\Filament\Admin\Resources\FawryBanks\Pages;

use App\Filament\Admin\Resources\FawryBanks\FawryBankResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListFawryBanks extends ListRecords
{
    protected static string $resource = FawryBankResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}