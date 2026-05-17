<?php

namespace App\Filament\Admin\Resources\EmployeeBonuses\Pages;

use App\Filament\Admin\Resources\EmployeeBonuses\EmployeeBonusResource;
use Filament\Actions;
use Filament\Resources\Pages\ManageRecords;

class ManageEmployeeBonuses extends ManageRecords
{
    protected static string $resource = EmployeeBonusResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
