<?php

namespace App\Http\Resources\Flight;

use App\Enums\FlightBookingStatus;
use App\Enums\FlightSystemType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FlightBookingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $status = $this->status instanceof FlightBookingStatus
            ? $this->status
            : FlightBookingStatus::tryFrom($this->status) ?? FlightBookingStatus::PENDING;

        $systemType = $this->system_type instanceof FlightSystemType
            ? $this->system_type
            : FlightSystemType::tryFrom($this->system_type) ?? FlightSystemType::Manual;

        $totalPaid = $this->whenLoaded('payments', fn () => $this->payments->sum('amount'), 0);
        $remaining = $this->selling_price - $totalPaid;
        $profitMargin = $this->selling_price > 0
            ? round(($this->profit / $this->selling_price) * 100, 2)
            : 0;

        $paymentStatus = $this->resource->computePaymentStatus();
        $paymentStatusLabel = match ($paymentStatus) {
            'paid' => 'مدفوع بالكامل',
            'partial' => 'مدفوع جزئياً',
            default => 'غير مدفوع',
        };

        return [
            'id' => $this->id,
            'booking_number' => $this->booking_number,
            'agent_name' => $this->agent_name,
            'system_type' => $this->system_type,
            'system_type_label' => $systemType->label(),
            'pnr' => $this->pnr,
            'airline_name' => $this->airline_name,
            'from_airport' => $this->from_airport,
            'to_airport' => $this->to_airport,
            'route' => $this->from_airport && $this->to_airport
                ? "{$this->from_airport} → {$this->to_airport}"
                : null,
            'departure_date' => $this->departure_date?->format('Y-m-d'),
            'return_date' => $this->return_date?->format('Y-m-d'),
            'trip_type' => $this->trip_type,
            'departure_time' => $this->departure_time?->format('H:i'),
            'arrival_time' => $this->arrival_time?->format('H:i'),
            'customer_id' => $this->customer_id,
            'employee_id' => $this->employee_id,
            'from_airport_id' => $this->from_airport_id,
            'to_airport_id' => $this->to_airport_id,
            'account_id' => $this->account_id,
            'baggage_allowance_kg' => $this->baggage_allowance_kg !== null ? (float) $this->baggage_allowance_kg : null,
            'flight_group_id' => $this->flight_group_id,
            'purchase_price_foreign' => $this->purchase_price_foreign !== null ? (float) $this->purchase_price_foreign : null,
            'exchange_rate' => $this->exchange_rate !== null ? (float) $this->exchange_rate : null,
            'purchase_price_egp' => $this->purchase_price_egp !== null ? (float) $this->purchase_price_egp : null,
            'flight_system_id' => $this->flight_system_id,
            'flight_carrier_id' => $this->flight_carrier_id,
            'purchase_balance_source' => $this->purchase_balance_source,
            'flight_system' => $this->when(
                $this->relationLoaded('flightSystem') && $this->flightSystem,
                fn () => [
                    'id' => $this->flightSystem->id,
                    'name' => $this->flightSystem->name,
                    'code' => $this->flightSystem->code,
                    'type' => $this->flightSystem->type,
                    'is_active' => $this->flightSystem->is_active,
                    'currency' => $this->flightSystem->currency,
                    'balance' => (float) $this->flightSystem->balance,
                    'credit_limit' => (float) $this->flightSystem->credit_limit,
                    'available_balance' => (float) $this->flightSystem->available_balance,
                ]
            ),
            'flight_carrier' => $this->when(
                $this->relationLoaded('flightCarrier') && $this->flightCarrier,
                fn () => [
                    'id' => $this->flightCarrier->id,
                    'name' => $this->flightCarrier->name,
                    'currency' => $this->flightCarrier->currency,
                    'balance' => (float) $this->flightCarrier->balance,
                    'available_balance' => (float) $this->flightCarrier->available_balance,
                    'is_active' => $this->flightCarrier->is_active,
                    'system' => $this->flightCarrier->relationLoaded('system') && $this->flightCarrier->system ? [
                        'id' => $this->flightCarrier->system->id,
                        'name' => $this->flightCarrier->system->name,
                    ] : null,
                ]
            ),
            'flight_group' => $this->when(
                $this->relationLoaded('flightGroup') && $this->flightGroup,
                fn () => [
                    'id' => $this->flightGroup->id,
                    'name' => $this->flightGroup->name,
                    'code' => $this->flightGroup->code,
                ]
            ),
            'passengers_count' => $this->passengers_count ?? $this->whenLoaded('passengers', fn () => $this->passengers->count(), 0),
            'trip_details' => $this->trip_details,
            'purchase_price' => (float) $this->purchase_price,
            'selling_price' => (float) $this->selling_price,
            'profit' => (float) $this->profit,
            'profit_margin' => number_format($profitMargin, 2).'%',
            'currency' => $this->currency ?? 'SAR',
            'currency_used' => $this->currency_used,
            'balance_currency_used' => $this->balance_currency_used,
            'exchange_rate_used' => $this->exchange_rate_used !== null ? (float) $this->exchange_rate_used : null,
            'status' => $status->value,
            'status_label' => $status->label(),
            'total_paid' => (float) $totalPaid,
            'remaining' => (float) $remaining,
            'payment_status' => $paymentStatus,
            'payment_status_label' => $paymentStatusLabel,
            'account' => $this->whenLoaded('account', fn () => [
                'id' => $this->account->id,
                'name' => $this->account->name,
            ]),
            'customer' => $this->whenLoaded('customer', fn () => [
                'id' => $this->customer->id,
                'name' => $this->customer->full_name,
                'phone' => $this->customer->phone,
                'email' => $this->customer->email,
                'type' => $this->customer->type instanceof \App\Enums\CustomerType
                    ? ($this->customer->type === \App\Enums\CustomerType::Company ? 'counter' : 'regular')
                    : ($this->customer->type === 'company' || $this->customer->type === 'counter' ? 'counter' : 'regular'),
            ]),
            'employee' => $this->whenLoaded('employee', fn () => [
                'id' => $this->employee->id,
                'name' => $this->employee->user?->name,
            ]),
            'passengers' => FlightPassengerResource::collection($this->whenLoaded('passengers')),
            'tickets' => FlightTicketResource::collection($this->whenLoaded('tickets')),
            'segments' => FlightSegmentResource::collection($this->whenLoaded('segments')),
            'payments' => FlightPaymentResource::collection($this->whenLoaded('payments')),
            'refund' => $this->whenLoaded('refund', fn () => new FlightRefundResource($this->refund)),
            'notes' => $this->notes,
            'created_by_id' => $this->whenLoaded('createdBy', fn () => $this->createdBy?->id),
            'created_by_name' => $this->whenLoaded('createdBy', fn () => $this->createdBy?->name),
            'created_at' => $this->created_at?->format('Y-m-d H:i:s'),
            'updated_at' => $this->updated_at?->format('Y-m-d H:i:s'),
        ];
    }
}
