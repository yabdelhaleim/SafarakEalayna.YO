<?php

namespace App\Filament\Admin\Resources\BusTreasuries\Pages;

use App\Filament\Admin\Resources\BusTreasuries\BusTreasuryResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditBusTreasury extends EditRecord
{
    protected static string $resource = BusTreasuryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\ViewAction::make(),
            Actions\DeleteAction::make(),
        ];
    }
}