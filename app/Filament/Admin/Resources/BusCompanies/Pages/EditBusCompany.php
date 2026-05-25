<?php

namespace App\Filament\Admin\Resources\BusCompanies\Pages;

use App\Filament\Admin\Resources\BusCompanies\BusCompanyResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditBusCompany extends EditRecord
{
    protected static string $resource = BusCompanyResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
