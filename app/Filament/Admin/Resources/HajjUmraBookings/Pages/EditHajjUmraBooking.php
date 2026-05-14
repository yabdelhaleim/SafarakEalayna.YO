<?php

namespace App\Filament\Admin\Resources\HajjUmraBookings\Pages;

use App\Filament\Admin\Resources\HajjUmraBookings\HajjUmraBookingResource;
use App\Models\HajjUmraBooking;
use App\Services\HajjUmra\HajjUmraBookingService;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditHajjUmraBooking extends EditRecord
{
    protected static string $resource = HajjUmraBookingResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()->label('إلغاء')];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var HajjUmraBooking $record */
        return app(HajjUmraBookingService::class)->update($record, $data);
    }
}
