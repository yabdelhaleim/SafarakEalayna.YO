<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FawryTransactionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $profit = bcsub((string) $this->selling_price, (string) $this->fawry_price, 2);

        return [
            'id' => $this->id,
            'client_name' => $this->client_name,
            'operation_type' => $this->operation_type,
            'client_amount' => number_format((float) $this->client_amount, 2),
            'fawry_price' => number_format((float) $this->fawry_price, 2),
            'selling_price' => number_format((float) $this->selling_price, 2),
            'profit' => number_format((float) $profit, 2),
            'profit_percentage' => (float) $this->fawry_price > 0
                ? round(((float) $profit / (float) $this->fawry_price) * 100, 2)
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
