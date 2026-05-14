<?php

namespace App\Filament\Admin\Resources\FawryPaymentMethods\Pages;

use App\Filament\Admin\Resources\FawryPaymentMethods\FawryPaymentMethodResource;
use Filament\Resources\Pages\CreateRecord;

class CreateFawryPaymentMethod extends CreateRecord
{
    protected static string $resource = FawryPaymentMethodResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
