<?php

namespace App\Http\Resources\Employee;

use App\Enums\BonusType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EmployeeBonusResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $type = $this->type instanceof BonusType
            ? $this->type
            : BonusType::from($this->type);

        $signedAmount = $type === BonusType::Bonus
            ? '+'.number_format($this->amount, 2)
            : '-'.number_format($this->amount, 2);

        return [
            'id' => $this->id,
            'employee' => [
                'id' => $this->whenLoaded('employee.user', fn () => $this->employee->id),
                'name' => $this->whenLoaded('employee.user', fn () => $this->employee->user->name),
                'salary' => $this->whenLoaded('employee', fn () => (float) $this->employee->salary),
            ],
            'type' => $this->type,
            'type_label' => $type->label(),
            'amount' => (float) $this->amount,
            'amount_signed' => $signedAmount,
            'reason' => $this->reason,
            'account' => [
                'id' => $this->whenLoaded('account', fn () => $this->account?->id),
                'name' => $this->whenLoaded('account', fn () => $this->account?->name),
                'type' => $this->whenLoaded('account', fn () => $this->account?->type),
            ],
            'transaction_id' => $this->transaction_id,
            'created_by_id' => $this->whenLoaded('createdBy', fn () => $this->createdBy?->id),
            'created_by_name' => $this->whenLoaded('createdBy', fn () => $this->createdBy?->name),
            'created_at' => $this->created_at?->format('Y-m-d H:i:s'),
            'updated_at' => $this->updated_at?->format('Y-m-d H:i:s'),
        ];
    }
}
