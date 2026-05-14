<?php

namespace App\Filament\Admin\Resources\HajjUmraTreasuries\Pages;

use App\Filament\Admin\Resources\HajjUmraTreasuries\HajjUmraTreasuryResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListHajjUmraTreasuries extends ListRecords
{
    protected static string $resource = HajjUmraTreasuryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}