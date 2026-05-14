<?php

namespace App\Filament\Admin\Resources\FawryCurrencies\Pages;

use App\Filament\Admin\Resources\FawryCurrencies\FawryCurrencyResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditFawryCurrency extends EditRecord
{
    protected static string $resource = FawryCurrencyResource::class;

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
