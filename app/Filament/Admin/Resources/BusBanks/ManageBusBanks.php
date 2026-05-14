<?php

namespace App\Filament\Admin\Resources\BusBanks;

use Filament\Actions;
use Filament\Resources\Pages\ManageRecords;

class ManageBusBanks extends ManageRecords
{
    protected static string $resource = BusBankResource::class;

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
