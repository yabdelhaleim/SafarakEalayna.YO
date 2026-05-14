<?php

namespace App\Filament\Resources\Setting\OperationTypeResource\Pages;

use App\Filament\Resources\Setting\OperationTypeResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditOperationType extends EditRecord
{
    protected static string $resource = OperationTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
            Actions\ViewAction::make(),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
