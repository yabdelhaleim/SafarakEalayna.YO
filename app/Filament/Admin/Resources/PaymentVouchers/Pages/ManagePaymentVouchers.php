<?php

namespace App\Filament\Admin\Resources\PaymentVouchers\Pages;

use App\Filament\Admin\Resources\PaymentVouchers\PaymentVoucherResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManagePaymentVouchers extends ManageRecords
{
    protected static string $resource = PaymentVoucherResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('سند صرف جديد')
                ->using(function (array $data): \App\Models\Transaction {
                    return app(\App\Services\Finance\AccountingService::class)->recordExpense([
                        'amount' => $data['amount'],
                        'from_account_id' => $data['from_account_id'],
                        'to_account_id' => $data['to_account_id'] ?? null,
                        'module' => \App\Enums\TransactionModule::Finance->value,
                        'notes' => $data['notes'],
                    ]);
                }),
        ];
    }
}
