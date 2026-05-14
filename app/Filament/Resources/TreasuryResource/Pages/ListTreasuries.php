<?php

namespace App\Filament\Resources\TreasuryResource\Pages;

use App\Filament\Resources\TreasuryResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListTreasuries extends ListRecords
{
    protected static string $resource = TreasuryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
