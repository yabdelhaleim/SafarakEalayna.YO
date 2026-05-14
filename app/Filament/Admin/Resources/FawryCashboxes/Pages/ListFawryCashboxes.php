<?php

namespace App\Filament\Admin\Resources\FawryCashboxes\Pages;

use App\Filament\Admin\Resources\FawryCashboxes\FawryCashboxResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListFawryCashboxes extends ListRecords
{
    protected static string $resource = FawryCashboxResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}