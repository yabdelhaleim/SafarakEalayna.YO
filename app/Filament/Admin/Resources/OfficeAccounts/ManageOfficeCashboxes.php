<?php

namespace App\Filament\Admin\Resources\OfficeAccounts;

use Filament\Actions;
use Filament\Resources\Pages\ManageRecords;

class ManageOfficeCashboxes extends ManageRecords
{
    protected static string $resource = OfficeCashboxResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->mutateFormDataUsing(function (array $data): array {
                    $data['module_type'] = 'office';

                    return $data;
                }),
        ];
    }
}
