<?php

namespace App\Filament\Admin\Resources\OnlineGeneralTreasuries\Pages;

use App\Filament\Admin\Resources\OnlineGeneralTreasuries\OnlineGeneralTreasuryResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListOnlineGeneralTreasuries extends ListRecords
{
    protected static string $resource = OnlineGeneralTreasuryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->modal(false),
        ];
    }
}

