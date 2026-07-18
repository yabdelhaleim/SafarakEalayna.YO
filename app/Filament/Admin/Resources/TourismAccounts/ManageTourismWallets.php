<?php

namespace App\Filament\Admin\Resources\TourismAccounts;

use Filament\Actions;
use Filament\Resources\Pages\ManageRecords;

class ManageTourismWallets extends ManageRecords
{
    protected static string $resource = TourismWalletResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->mutateFormDataUsing(function (array $data): array {
                    $data['module_type'] = 'tourism';

                    return $data;
                }),
        ];
    }
}
