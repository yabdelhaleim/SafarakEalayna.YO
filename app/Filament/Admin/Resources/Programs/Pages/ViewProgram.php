<?php

namespace App\Filament\Admin\Resources\Programs\Pages;

use App\Filament\Admin\Resources\Programs\ProgramResource;
use App\Filament\Admin\Resources\Programs\Widgets\ProgramProfitability;
use Filament\Resources\Pages\ViewRecord;

class ViewProgram extends ViewRecord
{
    protected static string $resource = ProgramResource::class;

    protected function getHeaderWidgets(): array
    {
        return [
            ProgramProfitability::class,
        ];
    }
}
