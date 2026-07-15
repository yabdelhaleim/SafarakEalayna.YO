<?php

namespace App\Filament\Admin\Resources\TicketModifications\Pages;

use App\Filament\Admin\Resources\TicketModifications\TicketModificationResource;
use App\Services\Flight\ModificationService;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditTicketModification extends EditRecord
{
    protected static string $resource = TicketModificationResource::class;

    /**
     * Header actions: we REPLACE the default Actions\DeleteAction (which calls
     * Model::delete() directly) with a custom action that delegates to
     * ModificationService::reverseConfirmation.
     *
     * The custom action performs:
     *   - soft delete of the modification
     *   - credit back of AirlineAccount.balance (GAP-aware — see TODO)
     *   - restoration of booking.departure_date + destination from the snapshots
     *
     * Idempotency: throws RuntimeException on already-deleted (Notification shows it).
     */
    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('deleteWithReversal')
                ->label('حذف مع عكس القيود')
                ->icon('heroicon-o-trash')
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('حذف طلب التعديل مع عكس الأثر المالي')
                ->modalDescription(
                    'سيتم تنفيذ soft delete + عكس خصم AirlineAccount '
                    .'+ إرجاع بيانات الحجز الأصلية (departure_date, destination). '
                    .'هذه العملية لا يمكن التراجع عنها.'
                )
                ->modalSubmitActionLabel('نعم، احذف وعكس')
                ->action(function () {
                    $userId = auth()->id() ?: 1;
                    try {
                        app(ModificationService::class)->reverseConfirmation($this->record->id, $userId);
                        Notification::make()
                            ->title('تم حذف طلب التعديل وعكس كل الآثار بنجاح')
                            ->success()
                            ->send();
                        $this->redirect(TicketModificationResource::getUrl('index'));
                    } catch (\Throwable $e) {
                        Notification::make()
                            ->title('فشل الحذف')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();
                    }
                }),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['modified_by'] = auth()->id() ?: 1;
        
        // Recalculate totals dynamically
        $fee = (float) ($data['airline_change_fee'] ?? 0);
        $comm = (float) ($data['agency_commission'] ?? 0);
        $data['total_charged_to_customer'] = $fee + $comm;

        return $data;
    }
}
