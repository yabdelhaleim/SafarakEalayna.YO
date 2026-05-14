<?php

namespace App\Filament\Admin\Resources\Airports\Pages;

use App\Filament\Admin\Concerns\HasSafarakFlightModulePageStyles;
use App\Filament\Admin\Resources\Airports\AirportResource;
use Filament\Resources\Pages\EditRecord;

class EditAirport extends EditRecord
{
    use HasSafarakFlightModulePageStyles;

    protected static string $resource = AirportResource::class;
}
