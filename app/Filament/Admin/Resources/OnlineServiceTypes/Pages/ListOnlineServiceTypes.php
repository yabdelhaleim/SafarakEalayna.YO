<?php

namespace App\Filament\Admin\Resources\OnlineServiceTypes\Pages;

use App\Filament\Admin\Resources\OnlineServiceTypes\OnlineServiceTypeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListOnlineServiceTypes extends ListRecords
{
    protected static string $resource = OnlineServiceTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('نوع خدمة جديد'),
        ];
    }
}
