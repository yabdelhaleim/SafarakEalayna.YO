<?php

namespace App\Filament\Admin\Resources\Airports\Pages;

use App\Filament\Admin\Concerns\HasSafarakFlightModulePageStyles;
use App\Filament\Admin\Resources\Airports\AirportResource;
use Filament\Resources\Pages\CreateRecord;

class CreateAirport extends CreateRecord
{
    use HasSafarakFlightModulePageStyles;

    protected static string $resource = AirportResource::class;
}
