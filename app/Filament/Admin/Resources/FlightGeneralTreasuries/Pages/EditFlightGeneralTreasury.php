<?php

namespace App\Filament\Admin\Resources\FlightGeneralTreasuries\Pages;

use App\Enums\AccountType;
use App\Filament\Admin\Concerns\HasSafarakFlightModulePageStyles;
use App\Filament\Admin\Resources\FlightGeneralTreasuries\FlightGeneralTreasuryResource;
use Filament\Resources\Pages\EditRecord;

class EditFlightGeneralTreasury extends EditRecord
{
    use HasSafarakFlightModulePageStyles;

    protected static string $resource = FlightGeneralTreasuryResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['type'] = AccountType::Treasury->value;
        $data['module_type'] = 'flights';

        return $data;
    }
}

