<?php

namespace App\Filament\Admin\Resources\OnlineBankAccounts\Pages;

use App\Filament\Admin\Resources\OnlineBankAccounts\OnlineBankAccountResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListOnlineBankAccounts extends ListRecords
{
    protected static string $resource = OnlineBankAccountResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->modal(false),
        ];
    }
}

