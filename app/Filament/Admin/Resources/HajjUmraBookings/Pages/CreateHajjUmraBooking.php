<?php

namespace App\Filament\Admin\Resources\HajjUmraBookings\Pages;

use App\Filament\Admin\Resources\HajjUmraBookings\HajjUmraBookingResource;
use App\Services\HajjUmra\HajjUmraBookingService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateHajjUmraBooking extends CreateRecord
{
    protected static string $resource = HajjUmraBookingResource::class;

    /**
     * نمر بالخدمة لضمان ربط القيد المحاسبي بدلاً من الإنشاء المباشر.
     */
    protected function handleRecordCreation(array $data): Model
    {
        return app(HajjUmraBookingService::class)->create($data);
    }
}
