<?php

namespace App\Filament\Admin\Resources\FlightGroups\Pages;

use App\Filament\Admin\Concerns\HasSafarakFlightModulePageStyles;
use App\Filament\Admin\Resources\FlightGroups\FlightGroupResource;
use Filament\Resources\Pages\CreateRecord;

class CreateFlightGroup extends CreateRecord
{
    use HasSafarakFlightModulePageStyles;

    protected static string $resource = FlightGroupResource::class;
}
