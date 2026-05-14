<?php

namespace App\Filament\Admin\Resources\VisaWallets;

use Filament\Actions;
use Filament\Resources\Pages\ManageRecords;

class ManageVisaWallets extends ManageRecords
{
    protected static string $resource = VisaWalletResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->mutateFormDataUsing(function (array $data): array {
                    $data['module_type'] = 'visas';
                    return $data;
                }),
        ];
    }
}
