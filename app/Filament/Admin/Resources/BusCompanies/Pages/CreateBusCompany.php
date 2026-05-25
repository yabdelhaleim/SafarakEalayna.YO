<?php

namespace App\Filament\Admin\Resources\BusCompanies\Pages;

use App\Filament\Admin\Resources\BusCompanies\BusCompanyResource;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;

class CreateBusCompany extends CreateRecord
{
    protected static string $resource = BusCompanyResource::class;
}
