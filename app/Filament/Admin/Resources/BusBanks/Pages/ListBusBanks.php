<?php

namespace App\Filament\Admin\Resources\BusBanks\Pages;

use App\Filament\Admin\Resources\BusBanks\BusBankResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListBusBanks extends ListRecords
{
    protected static string $resource = BusBankResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}