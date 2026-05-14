<?php

namespace App\Filament\Admin\Resources\BusInventories\Pages;

use App\Filament\Admin\Resources\BusInventories\BusInventoryResource;
use App\Services\Bus\BusInventoryService;
use BackedEnum;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use Illuminate\Database\Eloquent\Model;

class ManageBusInventories extends ManageRecords
{
    protected static string $resource = BusInventoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->using(function (array $data, $livewire): Model {
                    $paymentType = $data['payment_type'] ?? null;
                    if ($paymentType instanceof BackedEnum) {
                        $paymentType = $paymentType->value;
                    }
                    $data['payment_type'] = $paymentType;

                    if (isset($data['departure_time']) && is_object($data['departure_time']) && method_exists($data['departure_time'], 'format')) {
                        $data['departure_time'] = $data['departure_time']->format('H:i:s');
                    }

                    return app(BusInventoryService::class)->createInventory($data);
                }),
        ];
    }
}
