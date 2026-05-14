<?php

namespace App\Filament\Admin\Resources\BusBanks\Pages;

use App\Filament\Admin\Resources\BusBanks\BusBankResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditBusBank extends EditRecord
{
    protected static string $resource = BusBankResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\ViewAction::make(),
            Actions\DeleteAction::make(),
        ];
    }
}