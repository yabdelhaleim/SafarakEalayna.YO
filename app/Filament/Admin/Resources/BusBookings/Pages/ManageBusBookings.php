<?php

namespace App\Filament\Admin\Resources\BusBookings\Pages;

use App\Filament\Admin\Resources\BusBookings\BusBookingResource;
use App\Services\Bus\BusBookingService;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class ManageBusBookings extends ManageRecords
{
    protected static string $resource = BusBookingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->using(function (array $data, $livewire): Model {
                    $customerId = $data['customer_id'] ?? null;
                    $name = trim((string) ($data['customer_name'] ?? ''));
                    $phone = trim((string) ($data['customer_phone'] ?? ''));

                    if (! $customerId && ($name === '' || $phone === '')) {
                        throw ValidationException::withMessages([
                            'customer_id' => 'اختر عميلاً مسجّلاً أو أدخل الاسم والهاتف لعميل مباشر.',
                        ]);
                    }

                    $payload = [
                        'inventory_id' => (int) $data['inventory_id'],
                        'customer_id' => $customerId ? (int) $customerId : null,
                        'customer_name' => $name !== '' ? $name : null,
                        'customer_phone' => $phone !== '' ? $phone : null,
                        'quantity' => (int) $data['quantity'],
                        'notes' => $data['notes'] ?? null,
                        'employee_id' => isset($data['employee_id']) && $data['employee_id'] !== ''
                            ? (int) $data['employee_id']
                            : null,
                    ];

                    return app(BusBookingService::class)->createBooking($payload);
                }),
        ];
    }
}
