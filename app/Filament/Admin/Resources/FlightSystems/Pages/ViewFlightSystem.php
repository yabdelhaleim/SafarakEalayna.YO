<?php

namespace App\Filament\Admin\Resources\FlightSystems\Pages;

use App\Filament\Admin\Concerns\HasSafarakFlightModulePageStyles;
use App\Filament\Admin\Resources\FlightSystems\FlightSystemResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewFlightSystem extends ViewRecord
{
    use HasSafarakFlightModulePageStyles;

    protected static string $resource = FlightSystemResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
