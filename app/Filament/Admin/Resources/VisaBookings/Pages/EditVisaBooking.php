<?php

namespace App\Filament\Admin\Resources\VisaBookings\Pages;

use App\Filament\Admin\Resources\VisaBookings\VisaBookingResource;
use App\Models\VisaBooking;
use App\Services\Visa\VisaBookingService;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditVisaBooking extends EditRecord
{
    protected static string $resource = VisaBookingResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()->label('إلغاء')];
    }

    /**
     * نملأ الحقول المتداخلة لـ visa_details من الـ relation عند فتح الـ Edit.
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var VisaBooking $record */
        $record = $this->getRecord();
        if ($record->visaDetail) {
            $detail = $record->visaDetail;
            $data['visa_details'] = [
                'visa_type' => $detail->visa_type?->value,
                'country' => $detail->country,
                'duration' => $detail->duration,
                'visa_duration_id' => $detail->visa_duration_id,
                'entry_type' => $detail->entry_type?->value,
                'visa_agent_id' => $detail->visa_agent_id,
                'executing_agent' => $detail->executing_agent,
                'submission_date' => $detail->submission_date?->format('Y-m-d'),
                'expected_result_date' => $detail->expected_result_date?->format('Y-m-d'),
                'visa_number' => $detail->visa_number,
            ];
        }
        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var VisaBooking $record */
        $detailFields = $data['visa_details'] ?? [];
        unset($data['visa_details']);

        // نحدّث visa_details مباشرة (ليس عبر إنشاء قيود محاسبية مكررة)
        if ($record->visaDetail && !empty($detailFields)) {
            $record->visaDetail->update(array_filter($detailFields, fn ($v) => $v !== null && $v !== ''));
        }

        // visa_number قد يُمرَّر منفرداً للسيرفس (للقبول)
        if (!empty($detailFields['visa_number'])) {
            $data['visa_number'] = $detailFields['visa_number'];
        }

        return app(VisaBookingService::class)->update($record, $data);
    }
}
