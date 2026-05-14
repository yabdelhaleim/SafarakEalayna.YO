<?php

namespace App\Filament\Resources\Flight\RefundRequestResource\Pages;

use App\Filament\Resources\Flight\RefundRequestResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditRefundRequest extends EditRecord
{
    protected static string $resource = RefundRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
