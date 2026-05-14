<?php

namespace App\Filament\Admin\Resources\VisaBanks\Pages;

use App\Filament\Admin\Resources\VisaBanks\VisaBankAccountResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListVisaBankAccounts extends ListRecords
{
    protected static string $resource = VisaBankAccountResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}