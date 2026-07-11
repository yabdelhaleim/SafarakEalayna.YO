<?php

namespace App\Filament\Admin\Resources\BusCompanies\Pages;

use App\Filament\Admin\Resources\BusCompanies\BusCompanyResource;
use App\Models\Bus\BusCompany;
use App\Services\Bus\BusCompanyService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditBusCompany extends EditRecord
{
    protected static string $resource = BusCompanyResource::class;

    /**
     * Header actions for the Edit page.
     *
     * Filament's default `Actions\DeleteAction` would call `$record->delete()`,
     * which trips `BusCompany::deleting` and throws RuntimeException (the model
     * is guarded to prevent silent balance corruption). We instead invoke the
     * canonical `BusCompanyService::deleteCompany()`, which:
     *   - Checks the inventory existence constraint at the service level.
     *   - Wraps in `BusCompany::run()` to flip the deletion gate open.
     *   - Soft-deletes the row.
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('deleteCompany')
                ->label('حذف')
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('حذف شركة الباص')
                ->modalDescription(
                    'سيتم حذف الشركة (soft-delete) ولن تظهر في القوائم. '
                    .'يجب ألّا تكون هناك رحلات نشطة مرتبطة بها.'
                )
                ->modalSubmitActionLabel('نعم، احذف الشركة')
                ->action(function (BusCompany $record): void {
                    try {
                        app(BusCompanyService::class)->deleteCompany($record);

                        Notification::make()
                            ->title('تم حذف الشركة')
                            ->body('تم أرشفة الشركة ولن تظهر في القوائم.')
                            ->success()
                            ->send();

                        $this->redirect($this->getResource()::getUrl('index'));
                    } catch (\Throwable $e) {
                        Notification::make()
                            ->title('فشل حذف الشركة')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();

                        throw $e;
                    }
                }),
        ];
    }
}
