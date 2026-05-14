<?php

namespace App\Filament\Admin\Resources\BusTickets\Pages;

use App\Filament\Admin\Resources\BusTickets\BusTicketResource;
use Filament\Resources\Pages\ManageRecords;

class ManageBusTickets extends ManageRecords
{
    protected static string $resource = BusTicketResource::class;
}