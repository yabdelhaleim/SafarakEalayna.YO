<?php

namespace App\Services\Finance;

use App\Enums\TransactionType;
use App\Models\AccountEntry;
use App\Models\Bus\BusBooking;
use App\Models\Flight\FlightBooking;
use App\Models\HajjUmraBooking;
use App\Models\Online\OnlineTransaction;
use App\Models\Transaction;
use App\Models\Transfer;
use App\Models\VisaBooking;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

class LedgerEntryDescriptionResolver
{
    public function resolve(AccountEntry $entry): string
    {
        $transaction = $entry->relationLoaded('transaction') ? $entry->transaction : $entry->transaction()->first();

        return $this->resolveFromTransaction($transaction, $entry);
    }

    public function resolveFromTransaction(?Transaction $transaction, ?AccountEntry $entry = null): string
    {
        if (! $transaction) {
            $stored = trim((string) ($entry?->notes ?? ''));

            return $stored !== '' ? $stored : 'معاملة مالية';
        }

        $built = $this->buildFromRelated($transaction);
        if ($built !== null) {
            return $built;
        }

        $stored = trim((string) ($entry?->notes ?: ($transaction->notes ?? '')));

        return $stored !== '' ? $stored : 'معاملة مالية';
    }

    /**
     * @return array<string, mixed>|null
     */
    public function bookingDetails(?Transaction $transaction): ?array
    {
        if (! $transaction?->related_type || ! $transaction->related_id) {
            return null;
        }

        $related = $transaction->relationLoaded('related')
            ? $transaction->related
            : $transaction->related()->first();

        if (! $related) {
            return null;
        }

        return match (true) {
            $related instanceof FlightBooking => $this->flightBookingDetails($related),
            $related instanceof BusBooking => $this->busBookingDetails($related),
            $related instanceof VisaBooking => $this->visaBookingDetails($related),
            $related instanceof HajjUmraBooking => $this->hajjBookingDetails($related),
            $related instanceof OnlineTransaction => $this->onlineTransactionDetails($related),
            default => null,
        };
    }

    public function forFlightBooking(FlightBooking $booking, ?Transaction $transaction = null): string
    {
        $booking->loadMissing(['customer', 'passengers', 'fromAirport', 'toAirport']);

        $airline = $booking->airline_name ?: '—';
        $passengerNames = $this->passengerNamesList($booking) ?: '—';
        $route = $this->flightRoute($booking);
        $travelDate = $this->formatDate($booking->departure_date);

        $line = $this->formatSlashLine(
            'حجز طيران',
            'المسافر: ' . $passengerNames,
            'الوجهة: ' . $route,
            'تاريخ: ' . $travelDate,
            'الناقل: ' . $airline
        );

        return $this->withContextPrefix($line, $transaction);
    }

    public function forBusBooking(BusBooking $booking, ?Transaction $transaction = null): string
    {
        $booking->loadMissing(['customer', 'inventory']);

        $route = trim((string) ($booking->inventory?->route ?? '—'));
        $date = $this->formatDate($booking->inventory?->travel_date);
        $qty = (int) ($booking->quantity ?? 1);

        $line = $this->formatSlashLine(
            'حجز تذكرة باص للعميل',
            $booking->customer?->full_name ?: '—',
            $route !== '' ? $route : '—',
            $date,
        );

        if ($qty > 1) {
            $line .= " — {$qty} مقاعد";
        }

        return $this->withContextPrefix($line, $transaction);
    }

    public function forVisaBooking(VisaBooking $booking, ?Transaction $transaction = null): string
    {
        $booking->loadMissing(['customer', 'visaDetail']);

        $country = $booking->visaDetail?->country ?? $booking->visaDetail?->destination ?? '—';
        $line = $this->formatSlashLine(
            'طلب تأشيرة للعميل',
            $booking->customer?->full_name ?: '—',
            (string) $country,
            $this->formatDate($booking->created_at),
        );

        return $this->withContextPrefix($line, $transaction);
    }

    public function forHajjUmraBooking(HajjUmraBooking $booking, ?Transaction $transaction = null): string
    {
        $booking->loadMissing(['customer', 'program']);

        $line = $this->formatSlashLine(
            'حجز برنامج حج أو عمرة للعميل',
            $booking->customer?->full_name ?: '—',
            $booking->program?->program_name ?? '—',
            $this->formatDate($booking->created_at),
        );

        return $this->withContextPrefix($line, $transaction);
    }

    public function forOnlineTransaction(OnlineTransaction $tx, ?Transaction $transaction = null): string
    {
        $tx->loadMissing(['serviceType', 'provider']);

        $line = $this->formatSlashLine(
            'خدمة أونلاين للعميل',
            $tx->customer_name ?: '—',
            $tx->serviceType?->name ?? $tx->provider?->name ?? '—',
            $this->formatDate($tx->created_at),
        );

        if ($tx->reference_number) {
            $line .= ' — مرجع: '.$tx->reference_number;
        }

        return $this->withContextPrefix($line, $transaction);
    }

    protected function buildFromRelated(Transaction $transaction): ?string
    {
        if (! $transaction->related_type || ! $transaction->related_id) {
            return null;
        }

        $related = $transaction->relationLoaded('related')
            ? $transaction->related
            : $transaction->related()->with($this->defaultEagerLoads($transaction->related_type))->first();

        if (! $related) {
            return null;
        }

        return match (true) {
            $related instanceof FlightBooking => $this->forFlightBooking($related, $transaction),
            $related instanceof BusBooking => $this->forBusBooking($related, $transaction),
            $related instanceof VisaBooking => $this->forVisaBooking($related, $transaction),
            $related instanceof HajjUmraBooking => $this->forHajjUmraBooking($related, $transaction),
            $related instanceof OnlineTransaction => $this->forOnlineTransaction($related, $transaction),
            $related instanceof Transfer => $this->forTransfer($related, $transaction),
            default => null,
        };
    }

    /**
     * @return array<int, string>
     */
    protected function defaultEagerLoads(string $relatedType): array
    {
        return match ($relatedType) {
            FlightBooking::class => ['customer', 'passengers', 'fromAirport', 'toAirport'],
            BusBooking::class => ['customer', 'inventory'],
            VisaBooking::class => ['customer', 'visaDetail'],
            HajjUmraBooking::class => ['customer', 'program'],
            OnlineTransaction::class => ['serviceType', 'provider'],
            default => [],
        };
    }

    protected function forTransfer(Transfer $transfer, Transaction $transaction): string
    {
        $from = $transaction->fromAccount?->name ?? '—';
        $to = $transaction->toAccount?->name ?? '—';

        return $this->formatSlashLine('تحويل مالي', $from, $to, $this->formatDate($transaction->created_at));
    }

    protected function flightCustomerName(FlightBookingModel|FlightBooking $booking): string
    {
        $booking->loadMissing('customer');

        $name = trim((string) ($booking->customer?->full_name ?? ''));

        return $name !== '' ? $name : '—';
    }

    protected function flightRoute(FlightBookingModel|FlightBooking $booking): string
    {
        $booking->loadMissing(['fromAirport', 'toAirport']);

        $from = trim((string) (
            $booking->fromAirport?->city_name_ar
            ?? $booking->from_airport
            ?? $booking->origin
            ?? ''
        ));
        $to = trim((string) (
            $booking->toAirport?->city_name_ar
            ?? $booking->to_airport
            ?? $booking->destination
            ?? ''
        ));

        if ($from !== '' && $to !== '') {
            return "{$from} - {$to}";
        }

        return trim("{$from} - {$to}", ' -') ?: '—';
    }

    protected function flightPassengerSuffix(FlightBookingModel|FlightBooking $booking): string
    {
        $booking->loadMissing('passengers');

        $count = $booking->relationLoaded('passengers') && $booking->passengers->isNotEmpty()
            ? $booking->passengers->count()
            : (int) ($booking->passenger_count ?? 0);

        if ($count <= 1) {
            return '';
        }

        $names = $this->passengerNamesList($booking);

        return $names !== ''
            ? "{$count} مسافرين ({$names})"
            : "{$count} مسافرين";
    }

    protected function passengerNamesList(FlightBookingModel|FlightBooking $booking): string
    {
        if (! $booking->relationLoaded('passengers')) {
            $booking->load('passengers');
        }

        return $booking->passengers
            ->map(function ($p) {
                $full = trim(trim((string) ($p->first_name ?? '')).' '.trim((string) ($p->last_name ?? '')));

                return $full !== '' ? $full : trim((string) ($p->name ?? ''));
            })
            ->filter()
            ->unique()
            ->implode('، ');
    }

    /**
     * @return array<string, mixed>
     */
    protected function flightBookingDetails(FlightBookingModel|FlightBooking $booking): array
    {
        $booking->loadMissing(['customer', 'passengers', 'fromAirport', 'toAirport']);

        return [
            'booking_id' => $booking->id,
            'booking_number' => $booking->booking_number ?? $booking->booking_reference,
            'pnr' => $booking->pnr,
            'provider_name' => $booking->airline_name ?? $booking->airline,
            'route' => $this->flightRoute($booking),
            'travel_date' => $this->formatDate($booking->departure_date),
            'passengers' => $this->passengerNamesList($booking) ?: '—',
            'passenger_count' => max(
                $booking->passengers->count(),
                (int) ($booking->passenger_count ?? 0),
            ),
            'status' => $booking->status instanceof \BackedEnum ? $booking->status->value : $booking->status,
            'selling_price' => (float) ($booking->selling_price ?? 0),
            'total_paid' => method_exists($booking, 'computePaymentStatus')
                ? (float) ($booking->paid_amount ?? 0)
                : 0.0,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function busBookingDetails(BusBooking $booking): array
    {
        $booking->loadMissing(['customer', 'inventory']);

        return [
            'booking_id' => $booking->id,
            'booking_number' => (string) $booking->id,
            'route' => $booking->inventory?->route,
            'travel_date' => $this->formatDate($booking->inventory?->travel_date),
            'quantity' => (int) $booking->quantity,
            'customer_name' => $booking->customer?->full_name,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function visaBookingDetails(VisaBooking $booking): array
    {
        $booking->loadMissing(['customer', 'visaDetail']);

        return [
            'booking_id' => $booking->id,
            'country' => $booking->visaDetail?->country ?? $booking->visaDetail?->destination,
            'customer_name' => $booking->customer?->full_name,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function hajjBookingDetails(HajjUmraBooking $booking): array
    {
        $booking->loadMissing(['customer', 'program']);

        return [
            'booking_id' => $booking->id,
            'program_name' => $booking->program?->program_name,
            'customer_name' => $booking->customer?->full_name,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function onlineTransactionDetails(OnlineTransaction $tx): array
    {
        $tx->loadMissing(['serviceType', 'provider']);

        return [
            'reference_number' => $tx->reference_number,
            'service_name' => $tx->serviceType?->name,
            'provider_name' => $tx->provider?->name,
            'customer_name' => $tx->customer_name,
        ];
    }

    protected function withContextPrefix(string $line, ?Transaction $transaction): string
    {
        $prefix = $this->contextPrefix($transaction);
        if ($prefix === null) {
            return $line;
        }

        return "{$prefix} — {$line}";
    }

    protected function contextPrefix(?Transaction $transaction): ?string
    {
        if (! $transaction) {
            return null;
        }

        $notes = mb_strtolower((string) ($transaction->notes ?? ''));

        if (str_contains($notes, 'استرداد')) {
            return 'استرداد للعميل';
        }
        if (str_contains($notes, 'إلغاء')) {
            return 'إلغاء حجز';
        }
        if (str_contains($notes, 'سداد') || str_contains($notes, 'دفعة') || str_contains($notes, 'تحصيل')) {
            return 'سداد دفعة';
        }
        if (str_contains($notes, 'تكلفة') || str_contains($notes, 'شراء')) {
            return 'تكلفة شراء';
        }

        $type = $transaction->type;
        if ($type instanceof TransactionType) {
            if ($type === TransactionType::Income) {
                return 'سداد دفعة';
            }
            if ($type === TransactionType::Expense && str_contains($notes, 'صرف')) {
                return 'صرف للعميل';
            }
        }

        return null;
    }

    protected function formatSlashLine(string ...$parts): string
    {
        return implode(' / ', array_map(
            fn ($p) => trim((string) $p) !== '' ? trim((string) $p) : '—',
            $parts,
        ));
    }

    protected function formatDate(mixed $date): string
    {
        if ($date instanceof CarbonInterface) {
            return $date->format('d-m-Y');
        }

        if (is_string($date) && $date !== '') {
            try {
                return \Carbon\Carbon::parse($date)->format('d-m-Y');
            } catch (\Throwable) {
                return $date;
            }
        }

        return '—';
    }
}
