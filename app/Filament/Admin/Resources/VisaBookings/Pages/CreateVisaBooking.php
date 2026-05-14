<?php

namespace App\Filament\Admin\Resources\VisaBookings\Pages;

use App\Filament\Admin\Resources\VisaBookings\VisaBookingResource;
use App\Services\Visa\VisaBookingService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateVisaBooking extends CreateRecord
{
    protected static string $resource = VisaBookingResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return app(VisaBookingService::class)->create($data);
    }
}
