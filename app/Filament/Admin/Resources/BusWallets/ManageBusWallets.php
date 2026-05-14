<?php

namespace App\Filament\Admin\Resources\BusWallets;

use Filament\Actions;
use Filament\Resources\Pages\ManageRecords;

class ManageBusWallets extends ManageRecords
{
    protected static string $resource = BusWalletResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->mutateFormDataUsing(function (array $data): array {
                    $data['module_type'] = 'bus';
                    return $data;
                }),
        ];
    }
}
