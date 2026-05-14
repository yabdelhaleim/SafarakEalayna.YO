<?php

namespace App\Filament\Admin\Resources\TicketModifications\Pages;

use App\Filament\Admin\Resources\TicketModifications\TicketModificationResource;
use Filament\Resources\Pages\CreateRecord;

class CreateTicketModification extends CreateRecord
{
    protected static string $resource = TicketModificationResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['modified_by'] = auth()->id() ?: 1;
        $data['ip_address'] = request()->ip();
        
        // Auto-compute total charged to customer
        $fee = (float) ($data['airline_change_fee'] ?? 0);
        $comm = (float) ($data['agency_commission'] ?? 0);
        $data['total_charged_to_customer'] = $fee + $comm;

        return $data;
    }
}
