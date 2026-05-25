<?php

namespace App\Filament\Admin\Resources\FlightGroups\Pages;

use App\Filament\Admin\Concerns\HasSafarakFlightModulePageStyles;
use App\Filament\Admin\Resources\FlightGroups\FlightGroupResource;
use Filament\Resources\Pages\EditRecord;

class EditFlightGroup extends EditRecord
{
    use HasSafarakFlightModulePageStyles;

    protected static string $resource = FlightGroupResource::class;
}
