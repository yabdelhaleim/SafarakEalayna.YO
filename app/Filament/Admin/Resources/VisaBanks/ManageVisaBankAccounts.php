<?php

namespace App\Filament\Admin\Resources\VisaBanks;

use Filament\Actions;
use Filament\Resources\Pages\ManageRecords;

class ManageVisaBankAccounts extends ManageRecords
{
    protected static string $resource = VisaBankAccountResource::class;

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
