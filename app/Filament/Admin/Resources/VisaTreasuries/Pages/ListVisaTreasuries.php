<?php

namespace App\Filament\Admin\Resources\VisaTreasuries\Pages;

use App\Filament\Admin\Resources\VisaTreasuries\VisaTreasuryResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListVisaTreasuries extends ListRecords
{
    protected static string $resource = VisaTreasuryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}