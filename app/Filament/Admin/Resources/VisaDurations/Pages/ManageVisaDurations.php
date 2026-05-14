<?php

namespace App\Filament\Admin\Resources\VisaDurations\Pages;

use App\Filament\Admin\Resources\VisaDurations\VisaDurationResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageVisaDurations extends ManageRecords
{
    protected static string $resource = VisaDurationResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
