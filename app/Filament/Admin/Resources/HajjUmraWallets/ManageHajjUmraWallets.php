<?php

namespace App\Filament\Admin\Resources\HajjUmraWallets;

use Filament\Actions;
use Filament\Resources\Pages\ManageRecords;

class ManageHajjUmraWallets extends ManageRecords
{
    protected static string $resource = HajjUmraWalletResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->mutateFormDataUsing(function (array $data): array {
                    $data['module_type'] = 'hajj_umra';
                    return $data;
                }),
        ];
    }
}
