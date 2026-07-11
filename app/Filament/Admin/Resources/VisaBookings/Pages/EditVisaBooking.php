<?php

namespace App\Filament\Admin\Resources\VisaBookings\Pages;

use App\Filament\Admin\Resources\VisaBookings\VisaBookingResource;
use App\Models\VisaBooking;
use App\Services\Visa\VisaBookingService;
use Filament\Actions\Action;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditVisaBooking extends EditRecord
{
    protected static string $resource = VisaBookingResource::class;

    /**
     * Header action: "إلغاء" (Cancel).
     *
     * Filament's default `DeleteAction` would call `$record->delete()`,
     * which trips `VisaBooking::deleting` and throws RuntimeException
     * (the model is guarded to prevent silent balance corruption). We
     * instead invoke the canonical `VisaBookingService::cancel()`,
     * which:
     *   - Reverses each payment, income, and expense transaction via
     *     `TransactionService::reverseTransaction()` (additive — never
     *     destroys original transaction rows).
     *   - Keeps the booking row visible (status=Cancelled) for audit
     *     transparency.
     *   - Does NOT touch VisaPayment rows (they stay visible for the
     *     post-cancel audit; only deleteBookingWithReversal() removes
     *     them).
     *
     * Use the API `DELETE /api/v1/visa/bookings/{id}` for the same
     * behaviour from non-Filament callers.
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('cancelBooking')
                ->label('إلغاء')
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('إلغاء الحجز')
                ->modalDescription(
                    'سيتم إنشاء قيود عكسية جديدة لجميع الدفعات وقيدي البيع والشراء '
                    .'بدون حذف أي قيد أصلي. سيبقى الحجز ظاهراً بحالة "ملغى" '
                    .'وستبقى الدفعات ظاهرة لأغراض التدقيق.'
                )
                ->modalSubmitActionLabel('نعم، نفّذ الإلغاء')
                ->action(function (VisaBooking $record) {
                    /** @var VisaBookingService $service */
                    $service = app(VisaBookingService::class);
                    $service->cancel($record, 'إلغاء من Filament');

                    \Filament\Notifications\Notification::make()
                        ->title('تم إلغاء الحجز')
                        ->body(
                            'تم إنشاء القيود العكسية بنجاح. الحجز لا يزال ظاهراً في القوائم '
                            .'(status=Cancelled) لمراجعة التدقيق. الدفعات لم تُحذف.'
                        )
                        ->success()
                        ->send();

                    $this->redirect($this->getResource()::getUrl('index'));
                }),
        ];
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
