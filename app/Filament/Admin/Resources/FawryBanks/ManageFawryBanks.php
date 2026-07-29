<?php

namespace App\Filament\Admin\Resources\FawryBanks;

use Filament\Actions;
use Filament\Resources\Pages\ManageRecords;

class ManageFawryBanks extends ManageRecords
{
    protected static string $resource = FawryBankResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->mutateFormDataUsing(function (array $data): array {
                    $data['module_type'] = 'office';
                    $data['module'] = 'fawry';
                    return $data;
                }),
        ];
    }
}
