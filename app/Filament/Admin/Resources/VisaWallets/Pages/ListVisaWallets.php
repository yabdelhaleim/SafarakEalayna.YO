<?php

namespace App\Filament\Admin\Resources\VisaWallets\Pages;

use App\Filament\Admin\Resources\VisaWallets\VisaWalletResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListVisaWallets extends ListRecords
{
    protected static string $resource = VisaWalletResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}