<?php

namespace App\Filament\Resources\Flight\TicketModificationResource\Pages;

use App\Filament\Resources\Flight\TicketModificationResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditTicketModification extends EditRecord
{
    protected static string $resource = TicketModificationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['modified_by'] = auth()->id() ?: 1;
        
        // Recalculate totals dynamically
        $fee = (float) ($data['airline_change_fee'] ?? 0);
        $comm = (float) ($data['agency_commission'] ?? 0);
        $data['total_charged_to_customer'] = $fee + $comm;

        return $data;
    }
}
