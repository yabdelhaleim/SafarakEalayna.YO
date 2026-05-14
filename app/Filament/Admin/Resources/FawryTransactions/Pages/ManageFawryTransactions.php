<?php

namespace App\Filament\Admin\Resources\FawryTransactions\Pages;

use App\Filament\Admin\Resources\FawryTransactions\FawryTransactionResource;
use App\Models\Fawry\FawryTransaction;
use Filament\Actions\CreateAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;

class ManageFawryTransactions extends ManageRecords
{
    protected static string $resource = FawryTransactionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('إضافة معاملة')
                ->successNotificationTitle('تم إنشاء المعاملة')
                ->successNotification(function (Notification $notification, FawryTransaction $record): Notification {
                    return $notification
                        ->persistent()
                        ->body(FawryTransactionResource::apiEnvelopePreviewBody($record, 'Fawry transaction created successfully.'));
                }),
        ];
    }
}