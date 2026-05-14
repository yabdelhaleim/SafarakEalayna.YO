<?php

namespace App\Filament\Admin\Resources\OnlineTransactions\Pages;

use App\Filament\Admin\Resources\OnlineTransactions\OnlineTransactionResource;
use App\Filament\Admin\Resources\OnlineTransactions\Widgets\OnlineStats;
use App\Models\Online\OnlineTransaction;
use App\Services\Online\OnlineTransactionService;
use Filament\Actions\CreateAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;

class ManageOnlineTransactions extends ManageRecords
{
    protected static string $resource = OnlineTransactionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('معاملة جديدة')
                ->using(function (array $data): OnlineTransaction {
                    return app(OnlineTransactionService::class)->create($data);
                })
                ->successNotificationTitle('تم تنفيذ المعاملة')
                ->successNotification(function (Notification $notification, OnlineTransaction $record): Notification {
                    return $notification
                        ->persistent()
                        ->body(OnlineTransactionResource::apiEnvelopePreviewBody($record, 'Online transaction created successfully.'));
                }),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        return [
            OnlineStats::class,
        ];
    }
}
