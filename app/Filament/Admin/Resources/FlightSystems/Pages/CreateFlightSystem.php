<?php

namespace App\Filament\Admin\Resources\FlightSystems\Pages;

use App\Filament\Admin\Concerns\HasSafarakFlightModulePageStyles;
use App\Filament\Admin\Resources\FlightSystems\FlightSystemResource;
use Filament\Resources\Pages\CreateRecord;

class CreateFlightSystem extends CreateRecord
{
    use HasSafarakFlightModulePageStyles;

    protected static string $resource = FlightSystemResource::class;
}
