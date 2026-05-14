<?php

namespace App\Filament\Admin\Resources\VisaBookings\Pages;

use App\Filament\Admin\Resources\VisaBookings\VisaBookingResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListVisaBookings extends ListRecords
{
    protected static string $resource = VisaBookingResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('طلب تأشيرة جديد')];
    }

    protected function getHeaderWidgets(): array
    {
        return [
            VisaBookingResource\Widgets\VisaStats::class,
        ];
    }
}
