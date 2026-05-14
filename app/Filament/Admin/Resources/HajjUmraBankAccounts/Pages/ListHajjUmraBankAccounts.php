<?php

namespace App\Filament\Admin\Resources\HajjUmraBankAccounts\Pages;

use App\Filament\Admin\Resources\HajjUmraBankAccounts\HajjUmraBankAccountResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListHajjUmraBankAccounts extends ListRecords
{
    protected static string $resource = HajjUmraBankAccountResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->modal(false),
        ];
    }
}

