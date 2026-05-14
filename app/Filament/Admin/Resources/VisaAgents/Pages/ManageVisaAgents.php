<?php

namespace App\Filament\Admin\Resources\VisaAgents\Pages;

use App\Filament\Admin\Resources\VisaAgents\VisaAgentResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageVisaAgents extends ManageRecords
{
    protected static string $resource = VisaAgentResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
