<?php

namespace App\Filament\Admin\Resources\Passengers\Pages;

use App\Filament\Admin\Concerns\HasSafarakFlightModulePageStyles;
use App\Filament\Admin\Resources\Passengers\PassengerResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManagePassengers extends ManageRecords
{
    use HasSafarakFlightModulePageStyles;

    protected static string $resource = PassengerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
