<?php

namespace App\Filament\Admin\Resources\FlightTreasuries\Pages;

use App\Filament\Admin\Resources\FlightTreasuries\FlightTreasuryResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditFlightTreasury extends EditRecord
{
    protected static string $resource = FlightTreasuryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
