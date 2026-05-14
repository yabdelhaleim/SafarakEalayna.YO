<?php

namespace App\Filament\Admin\Resources\BusGeneralTreasuries\Pages;

use App\Filament\Admin\Resources\BusGeneralTreasuries\BusGeneralTreasuryResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;

class EditBusGeneralTreasury extends EditRecord
{
    protected static string $resource = BusGeneralTreasuryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
            ForceDeleteAction::make(),
            RestoreAction::make(),
        ];
    }
}
