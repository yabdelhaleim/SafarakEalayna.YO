<?php

namespace App\Filament\Admin\Resources\BusCompanyPayments\Pages;

use App\Filament\Admin\Resources\BusCompanyPayments\BusCompanyPaymentResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageBusCompanyPayments extends ManageRecords
{
    protected static string $resource = BusCompanyPaymentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->after(function (\App\Models\Bus\BusCompanyPayment $record) {
                    $company = $record->company;
                    if ($company && $company->account_id && $record->account_id && $record->amount > 0) {
                        $transactionService = app(\App\Services\Finance\TransactionService::class);
                        
                        $transactionService->recordTransfer([
                            'amount' => $record->amount,
                            'from_account_id' => $record->account_id,
                            'to_account_id' => $company->account_id,
                            'module' => \App\Enums\TransactionModule::Bus->value,
                            'related_type' => \App\Models\Bus\BusCompanyPayment::class,
                            'related_id' => $record->id,
                            'notes' => 'تسوية دفعة شركة باص: ' . $company->name . ($record->notes ? ' - ' . $record->notes : ''),
                        ]);
                    }
                }),
        ];
    }
}