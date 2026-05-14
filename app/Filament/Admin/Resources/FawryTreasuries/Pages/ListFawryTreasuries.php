<?php

namespace App\Filament\Admin\Resources\FawryTreasuries\Pages;

use App\Filament\Admin\Resources\FawryTreasuries\FawryTreasuryResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListFawryTreasuries extends ListRecords
{
    protected static string $resource = FawryTreasuryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}