<?php

namespace App\Filament\Admin\Resources\OnlineTreasuries\Pages;

use App\Filament\Admin\Resources\OnlineTreasuries\OnlineTreasuryResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListOnlineTreasuries extends ListRecords
{
    protected static string $resource = OnlineTreasuryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->modal(false),
        ];
    }
}

