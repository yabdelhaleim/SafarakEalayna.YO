<?php

namespace App\Filament\Admin\Resources\BusTreasuries;

use Filament\Actions;
use Filament\Resources\Pages\ManageRecords;

class ManageBusTreasuries extends ManageRecords
{
    protected static string $resource = BusTreasuryResource::class;

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
