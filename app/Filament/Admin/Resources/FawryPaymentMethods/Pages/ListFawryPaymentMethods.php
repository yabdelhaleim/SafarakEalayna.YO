<?php

namespace App\Filament\Admin\Resources\FawryPaymentMethods\Pages;

use App\Filament\Admin\Resources\FawryPaymentMethods\FawryPaymentMethodResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListFawryPaymentMethods extends ListRecords
{
    protected static string $resource = FawryPaymentMethodResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('إضافة طريقة دفع')
                ->modal(false),
        ];
    }
}
