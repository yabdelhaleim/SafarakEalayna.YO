<?php

namespace App\Filament\Admin\Resources\FawryCashboxes;

use Filament\Actions;
use Filament\Resources\Pages\ManageRecords;

class ManageFawryCashboxes extends ManageRecords
{
    protected static string $resource = FawryCashboxResource::class;

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
