<?php

namespace App\Filament\Resources\Flight\RefundRequestResource\Pages;

use App\Filament\Resources\Flight\RefundRequestResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListRefundRequests extends ListRecords
{
    protected static string $resource = RefundRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
