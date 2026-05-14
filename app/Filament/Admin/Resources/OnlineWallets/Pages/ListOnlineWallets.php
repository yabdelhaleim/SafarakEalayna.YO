<?php

namespace App\Filament\Admin\Resources\OnlineWallets\Pages;

use App\Filament\Admin\Resources\OnlineWallets\OnlineWalletResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListOnlineWallets extends ListRecords
{
    protected static string $resource = OnlineWalletResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->modal(false),
        ];
    }
}

