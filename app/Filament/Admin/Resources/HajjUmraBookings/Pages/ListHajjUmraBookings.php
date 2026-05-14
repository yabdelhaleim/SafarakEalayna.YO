<?php

namespace App\Filament\Admin\Resources\HajjUmraBookings\Pages;

use App\Filament\Admin\Resources\HajjUmraBookings\HajjUmraBookingResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListHajjUmraBookings extends ListRecords
{
    protected static string $resource = HajjUmraBookingResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('حجز جديد')];
    }

    protected function getHeaderWidgets(): array
    {
        return [
            HajjUmraBookingResource\Widgets\HajjUmraStats::class,
        ];
    }
}
