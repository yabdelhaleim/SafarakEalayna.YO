<?php

namespace App\Filament\Admin\Resources\VisaTreasuries;

use Filament\Actions;
use Filament\Resources\Pages\ManageRecords;

class ManageVisaTreasuries extends ManageRecords
{
    protected static string $resource = VisaTreasuryResource::class;

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
