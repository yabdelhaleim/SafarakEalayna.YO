<?php

namespace App\Http\Resources\Invoice;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InvoiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'invoice_number' => $this->invoice_number,
            'customer_id' => $this->customer_id,
            'customer' => $this->whenLoaded('customer', fn() => [
                'id' => $this->customer->id,
                'name' => $this->customer->name,
                'phone' => $this->customer->phone,
            ]),
            'type' => $this->type,
            'type_label' => $this->type ? $this->type->label() : null,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'status_color' => $this->status->color(),
            'reference' => [
                'type' => $this->reference_type,
                'id' => $this->reference_id,
            ],
            'dates' => [
                'invoice_date' => $this->invoice_date?->format('Y-m-d'),
                'due_date' => $this->due_date?->format('Y-m-d'),
                'paid_date' => $this->paid_date?->format('Y-m-d'),
                'is_overdue' => $this->isOverdue(),
            ],
            'amounts' => [
                'subtotal' => (float) $this->subtotal,
                'tax_amount' => (float) $this->tax_amount,
                'discount_amount' => (float) $this->discount_amount,
                'total_amount' => (float) $this->total_amount,
                'paid_amount' => (float) $this->paid_amount,
                'due_amount' => (float) $this->due_amount,
            ],
            'notes' => $this->notes,
            'terms' => $this->terms,
            'items_count' => $this->whenCounted('items'),
            'payments_count' => $this->whenCounted('payments'),
            'items' => $this->whenLoaded('items', fn() => InvoiceItemResource::collection($this->items)),
            'payments' => $this->whenLoaded('payments', fn() => InvoicePaymentResource::collection($this->payments)),
            'created_by' => $this->whenLoaded('createdBy', fn() => [
                'id' => $this->createdBy->id,
                'name' => $this->createdBy->name,
            ]),
            'can_be_paid' => $this->canBePaid(),
            'can_be_cancelled' => $this->canBeCancelled(),
            'created_at' => $this->created_at?->format('Y-m-d H:i:s'),
            'updated_at' => $this->updated_at?->format('Y-m-d H:i:s'),
        ];
    }
}
