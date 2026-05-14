<?php

namespace App\Filament\Admin\Resources\FlightSystems\Pages;

use App\Filament\Admin\Concerns\HasSafarakFlightModulePageStyles;
use App\Filament\Admin\Resources\FlightSystems\FlightSystemResource;
use Filament\Resources\Pages\EditRecord;

class EditFlightSystem extends EditRecord
{
    use HasSafarakFlightModulePageStyles;

    protected static string $resource = FlightSystemResource::class;
}
