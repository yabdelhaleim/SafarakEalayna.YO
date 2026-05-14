<?php

namespace App\Filament\Admin\Resources\HajjUmraTreasuries;

use Filament\Actions;
use Filament\Resources\Pages\ManageRecords;

class ManageHajjUmraTreasuries extends ManageRecords
{
    protected static string $resource = HajjUmraTreasuryResource::class;

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
