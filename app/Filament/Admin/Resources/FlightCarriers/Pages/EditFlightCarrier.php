<?php

namespace App\Filament\Admin\Resources\FlightCarriers\Pages;

use App\Filament\Admin\Concerns\HasSafarakFlightModulePageStyles;
use App\Filament\Admin\Resources\FlightCarriers\FlightCarrierResource;
use Filament\Resources\Pages\EditRecord;

class EditFlightCarrier extends EditRecord
{
    use HasSafarakFlightModulePageStyles;

    protected static string $resource = FlightCarrierResource::class;
}
