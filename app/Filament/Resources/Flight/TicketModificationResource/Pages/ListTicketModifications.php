<?php

namespace App\Filament\Resources\Flight\TicketModificationResource\Pages;

use App\Filament\Resources\Flight\TicketModificationResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListTicketModifications extends ListRecords
{
    protected static string $resource = TicketModificationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        return [
            TicketModificationResource\Widgets\ModificationStatsWidget::class,
        ];
    }
}
