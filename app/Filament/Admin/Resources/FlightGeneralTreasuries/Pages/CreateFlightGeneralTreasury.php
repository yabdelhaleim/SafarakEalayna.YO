<?php

namespace App\Filament\Admin\Resources\FlightGeneralTreasuries\Pages;

use App\Enums\AccountType;
use App\Filament\Admin\Concerns\HasSafarakFlightModulePageStyles;
use App\Filament\Admin\Resources\FlightGeneralTreasuries\FlightGeneralTreasuryResource;
use Filament\Resources\Pages\CreateRecord;

class CreateFlightGeneralTreasury extends CreateRecord
{
    use HasSafarakFlightModulePageStyles;

    protected static string $resource = FlightGeneralTreasuryResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['type'] = AccountType::Treasury->value;
        $data['module_type'] = 'flights';

        return $data;
    }
}

