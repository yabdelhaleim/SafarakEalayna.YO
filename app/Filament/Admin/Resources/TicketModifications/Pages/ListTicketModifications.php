<?php

namespace App\Filament\Admin\Resources\TicketModifications\Pages;

use App\Filament\Admin\Resources\TicketModifications\TicketModificationResource;
use App\Filament\Admin\Resources\TicketModifications\Widgets\ModificationStatsWidget;
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
            ModificationStatsWidget::class,
        ];
    }
}
