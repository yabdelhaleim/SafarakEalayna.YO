<?php

namespace App\Filament\Admin\Resources\FawryCurrencies\Pages;

use App\Filament\Admin\Resources\FawryCurrencies\FawryCurrencyResource;
use Filament\Resources\Pages\CreateRecord;

class CreateFawryCurrency extends CreateRecord
{
    protected static string $resource = FawryCurrencyResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
