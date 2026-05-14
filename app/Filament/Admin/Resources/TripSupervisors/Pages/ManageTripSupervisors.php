<?php

namespace App\Filament\Admin\Resources\TripSupervisors\Pages;

use App\Filament\Admin\Resources\TripSupervisors\TripSupervisorResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageTripSupervisors extends ManageRecords
{
    protected static string $resource = TripSupervisorResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
