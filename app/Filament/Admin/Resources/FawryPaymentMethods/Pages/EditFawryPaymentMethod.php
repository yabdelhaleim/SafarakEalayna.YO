<?php

namespace App\Filament\Admin\Resources\FawryPaymentMethods\Pages;

use App\Filament\Admin\Resources\FawryPaymentMethods\FawryPaymentMethodResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditFawryPaymentMethod extends EditRecord
{
    protected static string $resource = FawryPaymentMethodResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
