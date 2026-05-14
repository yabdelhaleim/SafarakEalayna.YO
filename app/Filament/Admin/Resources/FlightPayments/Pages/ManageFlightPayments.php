<?php

namespace App\Filament\Admin\Resources\FlightPayments\Pages;

use App\Filament\Admin\Resources\FlightPayments\FlightPaymentResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageFlightPayments extends ManageRecords
{
    protected static string $resource = FlightPaymentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
