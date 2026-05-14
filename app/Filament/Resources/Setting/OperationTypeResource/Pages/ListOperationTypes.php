<?php

namespace App\Filament\Resources\Setting\OperationTypeResource\Pages;

use App\Filament\Resources\Setting\OperationTypeResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListOperationTypes extends ListRecords
{
    protected static string $resource = OperationTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->label('إضافة نوع عملية'),
        ];
    }
}
