<?php

namespace App\Filament\Admin\Resources\EmployeeAttendances\Pages;

use App\Filament\Admin\Resources\EmployeeAttendances\EmployeeAttendanceResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageEmployeeAttendances extends ManageRecords
{
    protected static string $resource = EmployeeAttendanceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
