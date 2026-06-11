<?php

namespace App\Filament\Admin\Resources\HajjUmraBookings\Pages;

use App\Filament\Admin\Resources\HajjUmraBookings\HajjUmraBookingResource;
use App\Services\HajjUmra\HajjUmraBookingService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateHajjUmraBooking extends CreateRecord
{
    protected static string $resource = HajjUmraBookingResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $passengers = $this->buildPassengersArray($data);
        if ($passengers !== []) {
            $data['passengers'] = $passengers;
        }

        if (! empty($data['register_initial_payment']) && (float) ($data['initial_payment_amount'] ?? 0) > 0) {
            $data['initial_payment'] = [
                'amount' => (float) $data['initial_payment_amount'],
                'payment_method' => $data['initial_payment_method'] ?? 'cash',
                'account_id' => (int) ($data['initial_payment_account_id'] ?? $data['account_id'] ?? 0),
                'payment_date' => $data['initial_payment_date'] ?? now()->toDateString(),
                'reference' => $data['initial_payment_reference'] ?? null,
                'paid_by' => $data['initial_payment_paid_by'] ?? null,
            ];
        }

        unset(
            $data['register_initial_payment'],
            $data['initial_payment_amount'],
            $data['initial_payment_method'],
            $data['initial_payment_account_id'],
            $data['initial_payment_date'],
            $data['initial_payment_reference'],
            $data['initial_payment_paid_by'],
            $data['passenger_adult_count'],
            $data['passenger_adult_unit_price'],
            $data['passenger_child_with_bed_count'],
            $data['passenger_child_with_bed_unit_price'],
            $data['passenger_child_no_bed_count'],
            $data['passenger_child_no_bed_unit_price'],
            $data['passenger_infant_count'],
            $data['passenger_infant_unit_price'],
        );

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<array{category: string, count: int, unit_price: float, subtotal: float}>
     */
    protected function buildPassengersArray(array $data): array
    {
        $categories = [
            'adult' => ['count' => 'passenger_adult_count', 'unit_price' => 'passenger_adult_unit_price'],
            'child_with_bed' => ['count' => 'passenger_child_with_bed_count', 'unit_price' => 'passenger_child_with_bed_unit_price'],
            'child_no_bed' => ['count' => 'passenger_child_no_bed_count', 'unit_price' => 'passenger_child_no_bed_unit_price'],
            'infant' => ['count' => 'passenger_infant_count', 'unit_price' => 'passenger_infant_unit_price'],
        ];

        $passengers = [];

        foreach ($categories as $category => $fields) {
            $count = (int) ($data[$fields['count']] ?? 0);
            $unitPrice = (float) ($data[$fields['unit_price']] ?? 0);

            if ($count <= 0) {
                continue;
            }

            $passengers[] = [
                'category' => $category,
                'count' => $count,
                'unit_price' => $unitPrice,
                'subtotal' => round($count * $unitPrice, 2),
            ];
        }

        return $passengers;
    }

    /**
     * نمر بالخدمة لضمان ربط القيد المحاسبي بدلاً من الإنشاء المباشر.
     */
    protected function handleRecordCreation(array $data): Model
    {
        return app(HajjUmraBookingService::class)->create($data);
    }
}
