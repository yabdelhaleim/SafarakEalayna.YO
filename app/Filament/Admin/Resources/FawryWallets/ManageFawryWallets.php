<?php

namespace App\Filament\Admin\Resources\FawryWallets;

use Filament\Actions;
use Filament\Resources\Pages\ManageRecords;

class ManageFawryWallets extends ManageRecords
{
    protected static string $resource = FawryWalletResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->mutateFormDataUsing(function (array $data): array {
                    $data['module_type'] = 'fawry';
                    return $data;
                }),
        ];
    }
}
