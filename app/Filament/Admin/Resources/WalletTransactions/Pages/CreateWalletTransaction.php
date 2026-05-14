<?php

namespace App\Filament\Admin\Resources\WalletTransactions\Pages;

use App\Filament\Admin\Resources\WalletTransactions\WalletTransactionResource;
use App\Services\Wallet\WalletTransactionService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateWalletTransaction extends CreateRecord
{
    protected static string $resource = WalletTransactionResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        try {
            return app(WalletTransactionService::class)->createTransaction($data);
        } catch (\Throwable $e) {
            Notification::make()
                ->title('خطأ في إنشاء العملية')
                ->body($e->getMessage())
                ->danger()
                ->send();

            $this->halt();
        }
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'تم تسجيل العملية بنجاح';
    }
}
