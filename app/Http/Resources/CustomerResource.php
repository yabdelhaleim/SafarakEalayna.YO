<?php

namespace App\Http\Resources;

use App\Enums\CustomerType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CustomerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $apiType = 'regular';
        if ($this->type instanceof CustomerType) {
            $apiType = $this->type === CustomerType::Company ? 'counter' : 'regular';
        } else {
            $apiType = $this->type === 'company' ? 'counter' : 'regular';
        }

        $activeModules = [];
        if (($this->flight_bookings_count ?? 0) > 0) {
            $activeModules[] = ['id' => 'flight', 'name' => 'طيران', 'color' => 'bg-blue-500/20 text-blue-400 border-blue-500/30'];
        }
        if (($this->bus_bookings_count ?? 0) > 0) {
            $activeModules[] = ['id' => 'bus', 'name' => 'باصات', 'color' => 'bg-orange-500/20 text-orange-400 border-orange-500/30'];
        }
        if (($this->hajj_umra_bookings_count ?? 0) > 0) {
            $activeModules[] = ['id' => 'hajj', 'name' => 'حج وعمرة', 'color' => 'bg-green-500/20 text-green-400 border-green-500/30'];
        }
        if (($this->visa_bookings_count ?? 0) > 0) {
            $activeModules[] = ['id' => 'visa', 'name' => 'تأشيرات', 'color' => 'bg-purple-500/20 text-purple-400 border-purple-500/30'];
        }
        if (($this->fawry_transactions_count ?? 0) > 0) {
            $activeModules[] = ['id' => 'fawry', 'name' => 'فوري', 'color' => 'bg-yellow-500/20 text-yellow-400 border-yellow-500/30'];
        }
        if (($this->online_transactions_count ?? 0) > 0) {
            $activeModules[] = ['id' => 'online', 'name' => 'أونلاين', 'color' => 'bg-indigo-500/20 text-indigo-400 border-indigo-500/30'];
        }

        return [
            'id' => $this->id,
            'full_name' => $this->full_name,
            'name' => $this->full_name,
            'phone' => $this->phone,
            'national_id' => $this->national_id,
            'passport_number' => $this->passport_number,
            'passport_expiry' => $this->passport_expiry?->format('Y-m-d'),
            'date_of_birth' => $this->date_of_birth?->format('Y-m-d'),
            'city' => $this->city,
            'affiliation' => $this->affiliation,
            'whatsapp_number' => $this->whatsapp_number,
            'travel_country' => $this->travel_country,
            'type' => $apiType,
            'customer_tier' => $this->customer_tier?->value,
            'notes' => $this->notes,
            'balance' => (float) ($this->ledgerAccount?->balance ?? 0),
            'active_modules' => $activeModules,
            'created_by_id' => $this->whenLoaded('createdBy', fn () => $this->createdBy?->id),
            'created_by_name' => $this->whenLoaded('createdBy', fn () => $this->createdBy?->name),
            'created_at' => $this->created_at?->format('Y-m-d H:i:s'),
            'updated_at' => $this->updated_at?->format('Y-m-d H:i:s'),
        ];
    }
}
