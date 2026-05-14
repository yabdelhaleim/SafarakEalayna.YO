<?php

namespace App\Filament\Admin\Resources\OnlineServiceProviders\Pages;

use App\Filament\Admin\Resources\OnlineServiceProviders\OnlineServiceProviderResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListOnlineServiceProviders extends ListRecords
{
    protected static string $resource = OnlineServiceProviderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('مزود جديد'),
        ];
    }
}
