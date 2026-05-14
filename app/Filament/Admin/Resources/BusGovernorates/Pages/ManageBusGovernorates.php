<?php

namespace App\Filament\Admin\Resources\BusGovernorates\Pages;

use App\Filament\Admin\Resources\BusGovernorates\BusGovernorateResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageBusGovernorates extends ManageRecords
{
    protected static string $resource = BusGovernorateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}

