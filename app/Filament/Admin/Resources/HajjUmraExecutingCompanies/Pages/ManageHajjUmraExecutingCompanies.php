<?php

namespace App\Filament\Admin\Resources\HajjUmraExecutingCompanies\Pages;

use App\Filament\Admin\Resources\HajjUmraExecutingCompanies\HajjUmraExecutingCompanyResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageHajjUmraExecutingCompanies extends ManageRecords
{
    protected static string $resource = HajjUmraExecutingCompanyResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
