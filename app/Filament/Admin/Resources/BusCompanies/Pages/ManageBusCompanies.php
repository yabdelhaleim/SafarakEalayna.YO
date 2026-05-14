<?php

namespace App\Filament\Admin\Resources\BusCompanies\Pages;

use App\Filament\Admin\Resources\BusCompanies\BusCompanyResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageBusCompanies extends ManageRecords
{
    protected static string $resource = BusCompanyResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}