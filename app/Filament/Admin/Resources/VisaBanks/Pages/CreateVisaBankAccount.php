<?php

namespace App\Filament\Admin\Resources\VisaBanks\Pages;

use App\Filament\Admin\Resources\VisaBanks\VisaBankAccountResource;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;

class CreateVisaBankAccount extends CreateRecord
{
    protected static string $resource = VisaBankAccountResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['module_type'] = 'visas';
        return $data;
    }
}