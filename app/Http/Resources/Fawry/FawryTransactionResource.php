<?php

namespace App\Http\Resources\Fawry;

use App\Enums\FawryOperationType as FawryOperationTypeEnum;
use App\Enums\FawryPaymentMethod as FawryPaymentMethodEnum;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FawryTransactionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $operationLabels = $this->resolveOperationTypeLabels();
        $paymentLabels = $this->resolvePaymentMethodLabels();

        return [
            'id' => $this->id,
            'client_name' => $this->client_name,
            'operation_type' => $this->operation_type,
            'operation_type_label' => $operationLabels['label'],
            'operation_type_label_en' => $operationLabels['label_en'],
            'client_amount' => (float) $this->client_amount,
            'fawry_price' => (float) $this->fawry_price,
            'selling_price' => (float) $this->selling_price,
            'profit' => (float) $this->profit,
            'employee' => [
                'id' => $this->whenLoaded('employee', fn () => $this->employee?->id),
                'name' => $this->whenLoaded('employee', fn () => $this->employee?->name),
            ],
            'account' => [
                'id' => $this->whenLoaded('account', fn () => $this->account?->id),
                'name' => $this->whenLoaded('account', fn () => $this->account?->name),
                'type' => $this->whenLoaded('account', fn () => $this->account?->type),
            ],
            'payment_method' => $this->payment_method,
            'payment_method_label' => $paymentLabels['label'],
            'payment_method_label_en' => $paymentLabels['label_en'],
            'amount' => (float) $this->amount,
            'reference_number' => $this->reference_number,
            'notes' => $this->notes,
            'expense_transaction_id' => $this->expense_transaction_id,
            'income_transaction_id' => $this->income_transaction_id,
            'created_at' => $this->created_at?->format('Y-m-d H:i:s'),
            'updated_at' => $this->updated_at?->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * @return array{label: string, label_en: string}
     */
    protected function resolveOperationTypeLabels(): array
    {
        $code = (string) $this->operation_type;

        if ($this->relationLoaded('operationTypeRow') && $this->operationTypeRow) {
            $row = $this->operationTypeRow;

            return [
                'label' => $row->name_ar,
                'label_en' => $row->name_en ?: $code,
            ];
        }

        $enum = FawryOperationTypeEnum::tryFrom($code);
        if ($enum) {
            return [
                'label' => $enum->label(),
                'label_en' => $enum->labelEn(),
            ];
        }

        return [
            'label' => $code,
            'label_en' => $code,
        ];
    }

    /**
     * @return array{label: string, label_en: string}
     */
    protected function resolvePaymentMethodLabels(): array
    {
        $code = (string) $this->payment_method;

        if ($this->relationLoaded('paymentMethodRow') && $this->paymentMethodRow) {
            $row = $this->paymentMethodRow;

            return [
                'label' => $row->name_ar,
                'label_en' => $row->name_en ?: $code,
            ];
        }

        $enum = FawryPaymentMethodEnum::tryFrom($code);
        if ($enum) {
            return [
                'label' => $enum->label(),
                'label_en' => $enum->labelEn(),
            ];
        }

        return [
            'label' => $code,
            'label_en' => $code,
        ];
    }
}
