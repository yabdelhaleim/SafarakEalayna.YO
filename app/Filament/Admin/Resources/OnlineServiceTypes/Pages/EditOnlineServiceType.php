<?php

namespace App\Filament\Admin\Resources\OnlineServiceTypes\Pages;

use App\Filament\Admin\Resources\OnlineServiceTypes\OnlineServiceTypeResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditOnlineServiceType extends EditRecord
{
    protected static string $resource = OnlineServiceTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
