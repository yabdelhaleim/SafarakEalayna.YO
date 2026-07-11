<?php

namespace App\Filament\Admin\Resources\HajjUmraBookings\Pages;

use App\Filament\Admin\Resources\HajjUmraBookings\HajjUmraBookingResource;
use App\Models\HajjUmraBooking;
use App\Services\HajjUmra\HajjUmraBookingService;
use Filament\Actions\Action;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditHajjUmraBooking extends EditRecord
{
    protected static string $resource = HajjUmraBookingResource::class;

    /**
     * Header action: "إلغاء" (Cancel).
     *
     * Filament's default `DeleteAction` would call `$record->delete()`,
     * which trips `HajjUmraBooking::deleting` and throws RuntimeException
     * (the model is guarded to prevent silent balance corruption). We
     * instead invoke the canonical `HajjUmraBookingService::cancel()`,
     * which:
     *   - Reverses each payment, income, and expense transaction via
     *     `TransactionService::reverseTransaction()` (additive — never
     *     destroys original transaction rows).
     *   - Keeps the booking row visible (status=Cancelled) for audit
     *     transparency.
     *
     * Use the API `DELETE /api/v1/hajj-umra/bookings/{id}` for the same
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
                    .'بدون حذف أي قيد أصلي. سيبقى الحجز ظاهراً بحالة "ملغى".'
                )
                ->modalSubmitActionLabel('نعم، نفّذ الإلغاء')
                ->action(function (HajjUmraBooking $record) {
                    /** @var HajjUmraBookingService $service */
                    $service = app(HajjUmraBookingService::class);
                    $service->cancel($record, 'إلغاء من Filament');

                    \Filament\Notifications\Notification::make()
                        ->title('تم إلغاء الحجز')
                        ->body(
                            'تم إنشاء القيود العكسية بنجاح. الحجز لا يزال ظاهراً في القوائم '
                            .'(status=Cancelled) لمراجعة التدقيق.'
                        )
                        ->success()
                        ->send();

                    $this->redirect($this->getResource()::getUrl('index'));
                }),
        ];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var HajjUmraBooking $record */
        return app(HajjUmraBookingService::class)->update($record, $data);
    }
}
