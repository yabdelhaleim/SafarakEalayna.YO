<?php

namespace App\Filament\Admin\Resources\VisaBanks\Pages;

use App\Filament\Admin\Resources\VisaBanks\VisaBankAccountResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditVisaBankAccount extends EditRecord
{
    protected static string $resource = VisaBankAccountResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\ViewAction::make(),
            Actions\DeleteAction::make(),
        ];
    }
}