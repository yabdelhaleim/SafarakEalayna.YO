<?php

namespace App\Filament\Admin\Resources\HajjUmraBookings\Pages;

use App\Filament\Admin\Resources\HajjUmraBookings\HajjUmraBookingResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewHajjUmraBooking extends ViewRecord
{
    protected static string $resource = HajjUmraBookingResource::class;

    protected function getHeaderActions(): array
    {
        return [EditAction::make()];
    }
}
