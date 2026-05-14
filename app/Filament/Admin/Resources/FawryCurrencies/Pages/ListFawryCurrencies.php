<?php

namespace App\Filament\Admin\Resources\FawryCurrencies\Pages;

use App\Filament\Admin\Resources\FawryCurrencies\FawryCurrencyResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListFawryCurrencies extends ListRecords
{
    protected static string $resource = FawryCurrencyResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('إضافة عملة لفوري')
                ->modal(false),
        ];
    }
}
