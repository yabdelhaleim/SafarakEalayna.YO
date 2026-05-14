<?php

namespace App\Filament\Admin\Resources\BusTreasuries\Pages;

use App\Filament\Admin\Resources\BusTreasuries\BusTreasuryResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListBusTreasuries extends ListRecords
{
    protected static string $resource = BusTreasuryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}