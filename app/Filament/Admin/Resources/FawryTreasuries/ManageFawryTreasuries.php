<?php

namespace App\Filament\Admin\Resources\FawryTreasuries;

use Filament\Actions;
use Filament\Resources\Pages\ManageRecords;

class ManageFawryTreasuries extends ManageRecords
{
    protected static string $resource = FawryTreasuryResource::class;

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
