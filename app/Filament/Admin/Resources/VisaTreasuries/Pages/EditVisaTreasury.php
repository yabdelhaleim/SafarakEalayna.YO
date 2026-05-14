<?php

namespace App\Filament\Admin\Resources\VisaTreasuries\Pages;

use App\Filament\Admin\Resources\VisaTreasuries\VisaTreasuryResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditVisaTreasury extends EditRecord
{
    protected static string $resource = VisaTreasuryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\ViewAction::make(),
            Actions\DeleteAction::make(),
        ];
    }
}