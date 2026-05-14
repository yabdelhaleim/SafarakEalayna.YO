<?php

namespace App\Filament\Admin\Resources\ReceiptVouchers\Pages;

use App\Filament\Admin\Resources\ReceiptVouchers\ReceiptVoucherResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageReceiptVouchers extends ManageRecords
{
    protected static string $resource = ReceiptVoucherResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('سند قبض جديد')
                ->using(function (array $data): \App\Models\Transaction {
                    return app(\App\Services\Finance\AccountingService::class)->recordIncome([
                        'amount' => $data['amount'],
                        'to_account_id' => $data['to_account_id'],
                        'from_account_id' => $data['from_account_id'] ?? null,
                        'module' => \App\Enums\TransactionModule::Finance->value,
                        'notes' => $data['notes'],
                    ]);
                }),
        ];
    }
}
