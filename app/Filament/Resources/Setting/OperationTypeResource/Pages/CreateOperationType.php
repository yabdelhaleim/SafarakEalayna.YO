<?php

namespace App\Filament\Resources\Setting\OperationTypeResource\Pages;

use App\Filament\Resources\Setting\OperationTypeResource;
use Filament\Resources\Pages\CreateRecord;

class CreateOperationType extends CreateRecord
{
    protected static string $resource = OperationTypeResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
