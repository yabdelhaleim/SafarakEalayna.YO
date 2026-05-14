<?php

namespace App\Filament\Admin\Resources\OnlineServiceProviders\Pages;

use App\Filament\Admin\Resources\OnlineServiceProviders\OnlineServiceProviderResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditOnlineServiceProvider extends EditRecord
{
    protected static string $resource = OnlineServiceProviderResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
