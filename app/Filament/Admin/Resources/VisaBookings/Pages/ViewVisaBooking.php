<?php

namespace App\Filament\Admin\Resources\VisaBookings\Pages;

use App\Filament\Admin\Resources\VisaBookings\VisaBookingResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewVisaBooking extends ViewRecord
{
    protected static string $resource = VisaBookingResource::class;

    protected function getHeaderActions(): array
    {
        return [EditAction::make()];
    }
}
