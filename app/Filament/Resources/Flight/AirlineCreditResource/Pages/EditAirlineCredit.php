<?php

namespace App\Filament\Resources\Flight\AirlineCreditResource\Pages;

use App\Filament\Resources\Flight\AirlineCreditResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditAirlineCredit extends EditRecord
{
    protected static string $resource = AirlineCreditResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
