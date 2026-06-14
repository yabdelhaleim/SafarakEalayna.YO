<?php

namespace App\Http\Resources\Finance;

use App\Models\Bus\BusBooking;
use App\Models\Flight\FlightBooking;
use App\Models\HajjUmraBooking;
use App\Models\Online\OnlineTransaction;
use App\Models\Transfer;
use App\Models\VisaBooking;
use App\Services\Finance\LedgerEntryDescriptionResolver;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AccountEntryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $transaction = $this->transaction;
        $related = $transaction?->related;
        $resolver = app(LedgerEntryDescriptionResolver::class);

        $reference_id = null;
        $entity_name = null;
        $process_type = $transaction?->module?->label() ?? 'نظامي';
        $status = null;
        $provider_name = null;

        if ($related instanceof FlightBooking) {
            $reference_id = $related->booking_reference ?: $related->booking_number ?: $related->pnr;
            $entity_name = $related->customer?->full_name;
            $process_type = 'حجز طيران';
            $provider_name = $related->airline_name ?? $related->airline;
        } elseif ($related instanceof BusBooking) {
            $reference_id = (string) $related->id;
            $entity_name = $related->customer?->full_name;
            $process_type = 'حجز باص';
            $provider_name = $related->inventory?->company?->name;
        } elseif ($related instanceof OnlineTransaction) {
            $reference_id = $related->reference_number;
            $entity_name = $related->customer_name;
            $process_type = $related->serviceType?->name ?: 'خدمة أون لاين';
            $status = $related->status?->value;
            $provider_name = $related->provider?->name;
        } elseif ($related instanceof Transfer) {
            $process_type = 'تحويل مالي';
            $entity_name = $transaction->from_account_id == $this->account_id
                ? ($transaction->toAccount?->name)
                : ($transaction->fromAccount?->name);
        } elseif ($related instanceof VisaBooking) {
            $reference_id = (string) $related->id;
            $entity_name = $related->customer?->full_name;
            $process_type = 'تأشيرة';
        } elseif ($related instanceof HajjUmraBooking) {
            $reference_id = (string) $related->id;
            $entity_name = $related->customer?->full_name;
            $process_type = 'حج وعمرة';
        }

        $bookingDetails = $resolver->bookingDetails($transaction);
        $storedNotes = trim((string) ($this->notes ?: ($transaction?->notes ?? '')));

        return [
            'id' => $this->id,
            'account_id' => $this->account_id,
            'transaction_id' => $this->transaction_id,
            'type' => $transaction?->type?->value ?? ($this->credit > 0 ? 'credit' : 'debit'),
            'type_label' => $transaction?->type?->label() ?? ($this->credit > 0 ? 'إيداع/رصيد' : 'صرف'),
            'module' => $transaction?->module?->value,
            'module_label' => $transaction?->module?->label() ?? 'نظامي',
            'description' => $resolver->resolve($this->resource),
            'notes' => $storedNotes !== '' ? $storedNotes : null,
            'user_name' => $transaction?->createdBy?->name ?? 'النظام',
            'debit' => (float) $this->debit,
            'credit' => (float) $this->credit,
            'balance_after' => (float) $this->balance_after,
            'created_at' => $this->created_at?->format('Y-m-d H:i:s'),
            'date_human' => $this->created_at?->translatedFormat('j F Y, g:i a'),

            'reference_id' => $reference_id,
            'entity_name' => $entity_name,
            'process_type' => $process_type,
            'status' => $status,
            'provider_name' => $provider_name,
            'payment_method' => $transaction?->posting_channel ?: 'نقدي',
            'booking_details' => $bookingDetails,
        ];
    }
}
