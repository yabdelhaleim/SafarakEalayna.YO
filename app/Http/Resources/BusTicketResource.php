<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BusTicketResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $purchaseTotal = bcmul((string) $this->purchase_price, (string) $this->ticket_count, 2);
        $sellTotal = bcmul((string) $this->selling_price, (string) $this->ticket_count, 2);
        $profit = bcsub($sellTotal, $purchaseTotal, 2);

        return [
            'id' => $this->id,
            'passenger_name' => $this->passenger_name,
            'phone' => $this->phone,
            'country' => $this->country,
            'bus_name' => $this->bus_name,
            'ticket_count' => $this->ticket_count,
            'from_city' => $this->from_city,
            'to_city' => $this->to_city,
            'departure_date' => $this->departure_date?->format('Y-m-d'),
            'departure_time' => $this->departure_time,
            'return_date' => $this->return_date?->format('Y-m-d'),
            'return_time' => $this->return_time,
            'purchase_price' => number_format((float) $this->purchase_price, 2),
            'selling_price' => number_format((float) $this->selling_price, 2),
            'profit' => number_format((float) $profit, 2),
            'profit_percentage' => (float) $purchaseTotal > 0
                ? round(((float) $profit / (float) $purchaseTotal) * 100, 2)
                : 0,
            'payment' => [
                'method' => $this->payment_method,
                'method_label' => $this->paymentMethodLabel($this->payment_method),
                'amount' => number_format((float) $this->amount, 2),
                'reference' => $this->reference_number,
            ],
            'employee' => $this->whenLoaded('employee', fn () => [
                'id' => $this->employee?->id,
                'name' => $this->employee?->name,
            ]),
            'created_at' => $this->created_at?->format('Y-m-d H:i'),
        ];
    }

    private function paymentMethodLabel(string $method): string
    {
        return [
            'cash' => 'نقدي مصري',
            'bank_transfer' => 'بنك مصر',
            'cash_wallet' => 'كاش بريد / ياسر محفظة',
            'office_safe' => 'خزينة المكتب - سفرك علينا',
            'office_drawer' => 'درج المكتب',
        ][$method] ?? $method;
    }
}
