<?php

namespace App\Services\Flight;

use App\Enums\FlightPaymentMethod;
use App\Enums\PassengerType;
use App\Enums\FlightBookingStatus;
use App\Enums\TransactionModule;
use App\Models\Customer;
use App\Models\Flight\FlightBooking;
use App\Models\Flight\FlightPayment;
use App\Models\TreasuryTransaction;
use App\Services\Finance\TransactionService;
use App\Services\Finance\TreasuryAccountResolver;
use App\Services\Finance\TreasuryLedgerMirror;
use App\Services\Treasury\TreasuryService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class AviationService
{
    public function __construct(
        protected TransactionService $transactionService,
        protected TreasuryService $treasuryService,
    ) {}

    /**
     * BL-04: Auto Profit Calculation
     */
    public function calculateProfit(array $pricingData): array
    {
        if ($pricingData['currency'] === 'EGP') {
            $purchase = (float) $pricingData['purchase_price'];
            $selling = (float) $pricingData['selling_price'];
            $profit = $selling - $purchase;

            return [
                'purchase_price' => $purchase,
                'selling_price' => $selling,
                'profit' => $profit,
                'warnings' => $this->getProfitWarnings($profit),
            ];
        }

        $amount = (float) $pricingData['amount_in_foreign_currency'];
        $rate = (float) $pricingData['exchange_rate_used'];
        $purchaseEgp = $amount * $rate;
        $sellingEgp = (float) $pricingData['selling_price_egp'];
        $profitEgp = $sellingEgp - $purchaseEgp;

        return [
            'booking_currency' => $pricingData['currency'],
            'amount_in_foreign_currency' => $amount,
            'exchange_rate_used' => $rate,
            'purchase_price_egp' => $purchaseEgp,
            'selling_price_egp' => $sellingEgp,
            'profit_egp' => $profitEgp,
            'warnings' => $this->getProfitWarnings($profitEgp),
        ];
    }

    private function getProfitWarnings(float $profit): array
    {
        $warnings = [];
        if ($profit < 0) {
            $warnings[] = ['code' => 'NEGATIVE_MARGIN_ERROR', 'message' => 'سعر البيع أقل من سعر الشراء'];
        } elseif ($profit < 50) {
            $warnings[] = ['code' => 'MARGIN_WARNING', 'message' => 'هامش الربح منخفض جداً (< 50 جنيه)'];
        }

        return $warnings;
    }

    /**
     * BL-06: Passenger Rules
     */
    public function validatePassengers(array $passengers, Carbon $travelDate): array
    {
        $adults = 0;
        $children = 0;
        $infants = 0;
        $errors = [];

        foreach ($passengers as $p) {
            $type = $this->classifyPassengerType($p['date_of_birth'] ?? null, $travelDate);
            if ($type === PassengerType::ADULT) {
                $adults++;
            }
            if ($type === PassengerType::CHILD) {
                $children++;
            }
            if ($type === PassengerType::INFANT) {
                $infants++;
            }
        }

        if ($infants > $adults) {
            $errors[] = ['field' => 'passengers', 'code' => 'INFANT_EXCEEDS_ADULT', 'message' => 'عدد الرضّع أكثر من البالغين'];
        }

        if ($infants > 0 && $adults === 0) {
            $errors[] = ['field' => 'passengers', 'code' => 'INFANT_WITHOUT_ADULT', 'message' => 'رضيع بدون بالغ مرافق'];
        }

        return [
            'counts' => [
                'adults' => $adults,
                'children' => $children,
                'infants' => $infants,
                'total' => $adults + $children + $infants,
            ],
            'errors' => $errors,
        ];
    }

    private function classifyPassengerType(?string $dob, Carbon $travelDate): PassengerType
    {
        if (! $dob) {
            return PassengerType::ADULT;
        }

        $birthDate = Carbon::parse($dob);
        $ageInMonths = $birthDate->diffInMonths($travelDate);

        if ($ageInMonths < 24) {
            return PassengerType::INFANT;
        }
        if ($ageInMonths < 144) {
            return PassengerType::CHILD;
        }

        return PassengerType::ADULT;
    }

    protected function resolveTreasuryLedgerAccountId(?string $treasuryEnumValue, ?int $explicitAccountId): int
    {
        return TreasuryAccountResolver::resolve(
            isset($treasuryEnumValue) && $treasuryEnumValue !== '' ? $treasuryEnumValue : null,
            $explicitAccountId !== null && $explicitAccountId > 0 ? $explicitAccountId : null
        );
    }

    /**
     * OP-01: Create Booking
     */
    public function createBooking(array $data): array
    {
        return DB::transaction(function () use ($data) {
            $customer = Customer::updateOrCreate(
                ['phone' => $data['customer']['phone']],
                [
                    'full_name' => $data['customer']['full_name'],
                    'national_id' => $data['customer']['national_id'] ?? null,
                    'city' => $data['customer']['city'] ?? null,
                    'affiliation' => $data['customer']['affiliation'] ?? null,
                    'customer_tier' => $data['customer']['customer_tier'] ?? 'STANDARD',
                ]
            );

            $pricingResult = $this->calculateProfit($data['pricing']);

            $travelDate = Carbon::parse($data['flight']['departure_date']);
            $passengerValidation = $this->validatePassengers($data['passengers'], $travelDate);

            if (! empty($passengerValidation['errors'])) {
                throw new \Exception(json_encode(['errors' => $passengerValidation['errors']]));
            }

            $booking = FlightBooking::create([
                'booking_reference' => $data['booking_reference'],
                'booking_channel_type' => $data['booking_channel']['type'],
                'booking_channel_provider' => $data['booking_channel']['provider'],
                'status' => FlightBookingStatus::CONFIRMED,
                'customer_id' => $customer->id,
                'agent_name' => $data['agent_name'],
                'notes' => $data['notes'] ?? null,
                'origin' => $data['flight']['origin'],
                'destination' => $data['flight']['destination'],
                'departure_date' => $data['flight']['departure_date'],
                'departure_time' => $data['flight']['departure_time'],
                'return_date' => $data['flight']['return_date'] ?? null,
                'return_time' => $data['flight']['return_time'] ?? null,
                'trip_type' => $data['flight']['trip_type'],
                'airline' => $data['flight']['airline'],
                'passenger_count' => $passengerValidation['counts']['total'],
                'baggage_allowance_kg' => $data['flight']['baggage_allowance_kg'] ?? 0,
            ]);

            $booking->pricing()->create(array_merge(
                ['currency' => $data['pricing']['currency']],
                $pricingResult
            ));

            foreach ($data['passengers'] as $p) {
                $booking->passengers()->create([
                    'first_name' => $p['first_name'],
                    'last_name' => $p['last_name'],
                    'type' => $this->classifyPassengerType($p['date_of_birth'] ?? null, $travelDate),
                    'date_of_birth' => $p['date_of_birth'] ?? null,
                    'relation_to_customer' => $p['relation_to_customer'] ?? null,
                ]);
            }

            $pay = $data['payment'] ?? null;
            if (is_array($pay) && (float) ($pay['amount'] ?? 0) > 0) {
                $amount = (float) $pay['amount'];
                $accountId = $this->resolveTreasuryLedgerAccountId(
                    isset($pay['treasury_account']) ? (string) $pay['treasury_account'] : null,
                    isset($pay['account_id']) ? (int) $pay['account_id'] : null,
                );

                $userId = Auth::id() ?: 1;

                $paymentMethodRaw = isset($pay['payment_method']) ? (string) $pay['payment_method'] : FlightPaymentMethod::Cash->value;
                $normalizedMethod = FlightPaymentMethod::tryFrom($paymentMethodRaw)?->value
                    ?? FlightPaymentMethod::Cash->value;

                $tx = $this->transactionService->recordIncome([
                    'amount' => $amount,
                    'to_account_id' => $accountId,
                    'module' => TransactionModule::Flight->value,
                    'related_type' => FlightBooking::class,
                    'related_id' => $booking->id,
                    'notes' => 'Aviation API — '.$booking->booking_reference,
                    'created_by' => $userId,
                ]);

                TreasuryLedgerMirror::mirrorFlightInboundReceipt(
                    $tx,
                    $booking->id,
                    'حجز طيران (Aviation) — '.$booking->booking_reference,
                    $data['agent_name'],
                    isset($pay['treasury_account']) ? (string) $pay['treasury_account'] : null,
                );

                FlightPayment::create([
                    'flight_booking_id' => $booking->id,
                    'payment_method' => $normalizedMethod,
                    'amount' => $amount,
                    'currency' => $pay['currency'] ?? 'EGP',
                    'treasury_account' => isset($pay['treasury_account']) ? (string) $pay['treasury_account'] : (string) $accountId.'|resolved',
                    'transaction_reference' => (string) $tx->id,
                    'payment_date' => $pay['payment_date'] ?? now(),
                    'paid_by' => $pay['paid_by'] ?? $customer->full_name,
                    'account_id' => $accountId,
                    'transaction_id' => $tx->id,
                    'notes' => 'Aviation API',
                    'created_by' => $userId,
                ]);
            }

            return [
                'booking' => $booking->load(['customer', 'passengers', 'pricing', 'payments']),
                'passenger_summary' => $passengerValidation['counts'],
                'warnings' => array_merge(
                    $pricingResult['warnings'],
                    $customer->customer_tier->value === 'PREMIUM' ? [['code' => 'PREMIUM_CUSTOMER_FLAG', 'message' => "العميل {$customer->full_name} عميل مميز — تم التنبيه."]] : []
                ),
            ];
        });
    }

    /**
     * OP-02: Query Booking
     */
    public function getBooking($idOrRef)
    {
        return FlightBooking::with(['customer', 'passengers', 'pricing', 'payments'])
            ->where('id', $idOrRef)
            ->orWhere('booking_reference', $idOrRef)
            ->orWhereHas('customer', function ($q) use ($idOrRef) {
                $q->where('phone', $idOrRef);
            })
            ->first();
    }

    /**
     * OP-03: Update Booking
     */
    public function updateBooking(int $id, array $data): FlightBooking
    {
        return DB::transaction(function () use ($id, $data) {
            $booking = FlightBooking::findOrFail($id);

            if (isset($data['status'])) {
                $booking->status = $data['status'];
            }

            if (isset($data['notes'])) {
                $booking->notes = $data['notes'];
            }

            $booking->save();

            return $booking->load(['customer', 'passengers', 'pricing', 'payments']);
        });
    }

    /**
     * OP-04: Cancel Booking
     */
    public function cancelBooking(int $id, string $reason, string $agentName): FlightBooking
    {
        return DB::transaction(function () use ($id, $reason, $agentName) {
            $booking = FlightBooking::findOrFail($id);
            $booking->status = FlightBookingStatus::CANCELLED;
            $booking->notes = ($booking->notes ? $booking->notes."\n" : '').'سبب الإلغاء: '.$reason;
            $booking->save();

            return $booking->load(['customer', 'passengers', 'pricing', 'payments']);
        });
    }

    /**
     * OP-05: Booking Report
     */
    public function getReport(array $filters): array
    {
        $query = FlightBooking::with(['pricing', 'payments'])
            ->whereNotNull('booking_channel_type')
            ->whereIn('booking_channel_type', array_column(\App\Enums\BookingChannelType::cases(), 'value'));

        if (isset($filters['date_from'])) {
            $query->whereDate('created_at', '>=', $filters['date_from']);
        }
        if (isset($filters['date_to'])) {
            $query->whereDate('created_at', '<=', $filters['date_to']);
        }
        if (isset($filters['airline'])) {
            $query->where('airline', $filters['airline']);
        }

        $bookings = $query->get();

        $totalRevenue = $bookings->sum(function ($b) {
            return $b->pricing?->selling_price_egp ?? $b->pricing?->selling_price ?? $b->selling_price ?? 0;
        });

        $totalProfit = $bookings->sum(function ($b) {
            return $b->pricing?->profit_egp ?? $b->pricing?->profit ?? $b->profit ?? 0;
        });

        return [
            'bookings' => $bookings,
            'summary' => [
                'total_bookings' => $bookings->count(),
                'total_revenue' => $totalRevenue,
                'total_profit' => $totalProfit,
            ],
        ];
    }

    /**
     * OP-07: Treasury movement عبر المحاسبة المزدوجة (خدمة الخزينة الموحّدة).
     *
     * @return array<string, mixed>|\App\Models\TreasuryTransaction
     */
    public function transferFunds(array $data): TreasuryTransaction|array
    {
        return DB::transaction(function () use ($data) {
            $amount = (float) $data['amount'];
            if ($amount <= 0) {
                throw new \InvalidArgumentException('المبلغ يجب أن يكون أكبر من صفر.');
            }

            $userId = Auth::id() ?: 1;
            $reason = (string) ($data['reason'] ?? '');

            $fromRaw = isset($data['from_treasury']) ? (string) $data['from_treasury'] : '';
            $toRaw = isset($data['to_treasury']) ? (string) $data['to_treasury'] : '';

            $explicitFromId = isset($data['from_account_id']) ? (int) $data['from_account_id'] : null;
            $explicitToId = isset($data['to_account_id']) ? (int) $data['to_account_id'] : null;

            $fromId = null;
            if ($fromRaw !== '' || ($explicitFromId !== null && $explicitFromId > 0)) {
                $fromId = $this->resolveTreasuryLedgerAccountId($fromRaw !== '' ? $fromRaw : null, $explicitFromId);
            }

            $toId = $this->resolveTreasuryLedgerAccountId($toRaw !== '' ? $toRaw : null, $explicitToId);

            if ($fromId !== null && $fromId !== $toId) {
                return $this->treasuryService->transfer((int) $fromId, (int) $toId, $amount, $reason, null, $userId);
            }

            return $this->treasuryService->credit((int) $toId, $amount, $reason, null, $userId);
        });
    }
}
