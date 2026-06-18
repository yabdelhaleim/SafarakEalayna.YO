<?php

namespace App\Services\Flight;

use App\Enums\AccountType;
use App\Enums\FlightBookingStatus;
use App\Enums\FlightPaymentMethod;
use App\Enums\FlightSystemType;
use App\Enums\TransactionModule;
use App\Models\Account;
use App\Models\Airport;
use App\Models\Customer;
use App\Models\Flight\FlightBooking;
use App\Models\Flight\FlightCarrier;
use App\Models\Flight\FlightGroup;
use App\Models\Flight\FlightGroupTransaction;
use App\Models\Flight\FlightPassenger;
use App\Models\Flight\FlightPayment;
use App\Models\Flight\FlightRefund;
use App\Models\Flight\FlightSegment;
use App\Models\Flight\FlightSystem;
use App\Models\Flight\FlightTicket;
use App\Models\Setting\Currency;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Finance\LedgerClearingAccounts;
use App\Services\Finance\LedgerEntryDescriptionResolver;
use App\Services\Finance\PrepaidLedgerService;
use App\Services\Finance\TransactionService;
use App\Services\Finance\TreasuryLedgerMirror;
use App\Services\Treasury\TreasuryService;
use App\Support\Finance\LedgerBalanceMutationGuard;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class FlightBookingService
{
    /**
     * جنيه لكل 1 وحدة أجنبية — يُستخدم فقط إن لم يُوجد سعر في جدول العملات (مع تحذير في السجل).
     * يُفضّل ضبط الأسعار في /admin/currencies.
     *
     * @var array<string, float>
     */
    private const FALLBACK_EGP_PER_UNIT = [
        'USD' => 48.5,
        'KWD' => 157.5,
        'SAR' => 12.9,
        'EUR' => 52.3,
        'GBP' => 61.2,
    ];

    protected TransactionService $transactionService;

    protected TreasuryService $treasuryService;

    protected LedgerClearingAccounts $ledgerClearingAccounts;

    protected PrepaidLedgerService $prepaidLedgerService;

    public function __construct(
        TransactionService $transactionService,
        TreasuryService $treasuryService,
        LedgerClearingAccounts $ledgerClearingAccounts,
        PrepaidLedgerService $prepaidLedgerService,
    ) {
        $this->transactionService = $transactionService;
        $this->treasuryService = $treasuryService;
        $this->ledgerClearingAccounts = $ledgerClearingAccounts;
        $this->prepaidLedgerService = $prepaidLedgerService;
    }

    /**
     * Retrieve all flight bookings with optional filters.
     *
     * Filters: status, customer_id, employee_id, from_date, to_date,
     *          search (booking_number or airline_name), per_page.
     * Eager loads: customer, employee, account, passengers, createdBy.
     * Orders by created_at DESC.
     *
     * @return LengthAwarePaginator<FlightBooking>
     */
    public function getAllBookings(array $filters): LengthAwarePaginator
    {
        $query = FlightBooking::with([
            'customer',
            'employee.user',
            'account',
            'airlineAccount',
            'flightSystem',
            'flightCarrier.system',
            'passengers',
            'tickets',
            'segments',
            'payments',
            'createdBy',
        ]);

        if (isset($filters['status']) && $filters['status']) {
            $query->where('status', $filters['status']);
        }

        if (isset($filters['customer_id']) && $filters['customer_id']) {
            $query->where('customer_id', $filters['customer_id']);
        }

        if (isset($filters['employee_id']) && $filters['employee_id']) {
            $query->where('employee_id', $filters['employee_id']);
        }

        if (isset($filters['from_date']) && $filters['from_date']) {
            $query->whereDate('created_at', '>=', $filters['from_date']);
        }

        if (isset($filters['to_date']) && $filters['to_date']) {
            $query->whereDate('created_at', '<=', $filters['to_date']);
        }

        if (isset($filters['search']) && $filters['search']) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('booking_number', 'like', "%{$search}%")
                    ->orWhere('airline_name', 'like', "%{$search}%")
                    ->orWhere('pnr', 'like', "%{$search}%")
                    ->orWhereHas('customer', function ($customerQuery) use ($search) {
                        $customerQuery->where('full_name', 'like', "%{$search}%");
                    });
            });
        }

        if (isset($filters['system_type']) && $filters['system_type']) {
            $query->where('system_type', $filters['system_type']);
        }

        if (isset($filters['trip_type']) && $filters['trip_type']) {
            $query->where('trip_type', $filters['trip_type']);
        }

        if (isset($filters['currency']) && $filters['currency']) {
            $query->where('currency', $filters['currency']);
        }

        if (isset($filters['flight_system_id']) && $filters['flight_system_id']) {
            $query->where('flight_system_id', (int) $filters['flight_system_id']);
        }

        if (isset($filters['flight_carrier_id']) && $filters['flight_carrier_id']) {
            $query->where('flight_carrier_id', (int) $filters['flight_carrier_id']);
        }

        if (isset($filters['payment_status']) && $filters['payment_status']) {
            $paidExpr = '(SELECT COALESCE(SUM(amount),0) FROM flight_payments WHERE flight_payments.flight_booking_id = flight_bookings.id)';
            match ($filters['payment_status']) {
                'paid' => $query->whereRaw("{$paidExpr} >= flight_bookings.selling_price - 0.01"),
                'partial' => $query->whereRaw("{$paidExpr} > 0.01 AND {$paidExpr} < flight_bookings.selling_price - 0.01"),
                'unpaid' => $query->whereRaw("{$paidExpr} < 0.01"),
                default => null,
            };
        }

        if (isset($filters['from_airport']) && $filters['from_airport']) {
            $query->where('from_airport', $filters['from_airport']);
        }

        if (isset($filters['to_airport']) && $filters['to_airport']) {
            $query->where('to_airport', $filters['to_airport']);
        }

        if (isset($filters['departure_date_from']) && $filters['departure_date_from']) {
            $query->whereDate('departure_date', '>=', $filters['departure_date_from']);
        }

        if (isset($filters['departure_date_to']) && $filters['departure_date_to']) {
            $query->whereDate('departure_date', '<=', $filters['departure_date_to']);
        }

        $perPage = min($filters['per_page'] ?? 15, 100);

        return $query->orderBy('created_at', 'desc')->paginate($perPage);
    }

    /**
     * Create a new flight booking with complete double-entry accounting.
     *
     * Flow:
     * 1. Generate unique booking number
     * 2. Calculate prices & profit
     * 3. Create booking record
     * 4. Debit flight carrier (purchase cost)
     * 5. Credit treasury account (selling price) via balanced journal when configured, else treasury log
     * 6. Create passengers
     * 7. Create flight tickets (one per passenger, or one group ticket)
     * 8. Create segments
     * 9. Process initial payment if provided
     *
     * Profit is stored on the booking row only (selling − purchase); no separate P&L journal on create.
     *
     * Rollback: All operations are in a single DB transaction
     *
     * @param  array  $data  Validated booking data
     *
     * @throws \Exception
     */
    public function createBooking(array $data): FlightBooking
    {
        try {
            return DB::transaction(function () use ($data) {
                $data = $this->prepareFlightBookingPayload($data);

                $userId = Auth::id() ?: 1;

                // Step 1: Calculate pricing
                $currency = $data['currency'] ?? 'EGP';
                $purchasePriceEGP = 0;
                $sellingPrice = (float) ($data['selling_price'] ?? 0);

                if ($currency === 'EGP') {
                    $purchasePriceEGP = (float) ($data['purchase_price'] ?? 0);
                } else {
                    $purchasePriceForeign = (float) ($data['purchase_price_foreign'] ?? 0);
                    $exchangeRate = (float) ($data['exchange_rate'] ?? 1.0);
                    $purchasePriceEGP = $purchasePriceForeign * $exchangeRate;
                }

                $profit = $sellingPrice - $purchasePriceEGP;

                $purchaseBalanceSource = $this->resolvePurchaseBalanceSource($data);
                $settlementSnapshot = $this->persistedSettlementSnapshot($data, $currency, $purchasePriceEGP, $purchaseBalanceSource);
                $lockCarrier = $purchaseBalanceSource === 'carrier'
                    ? $this->lockForEntityDebit($data, 'carrier', $currency, $purchasePriceEGP)
                    : null;
                $lockSystem = $purchaseBalanceSource === 'system'
                    ? $this->lockForEntityDebit($data, 'system', $currency, $purchasePriceEGP)
                    : null;

                // Step 2: Generate unique booking number
                $bookingNumber = $this->generateBookingNumber();

                // Step 3: Create booking record
                $booking = FlightBooking::create([
                    'customer_id' => $data['customer_id'],
                    'employee_id' => $data['employee_id'] ?? null,
                    'booking_reference' => "FLT-{$bookingNumber}",
                    'booking_number' => "FLT-{$bookingNumber}",
                    'booking_channel_type' => $data['booking_channel_type'] ?? \App\Enums\BookingChannelType::SIGN->value,
                    'booking_channel_provider' => $data['booking_channel_provider'] ?? 'SIGN',
                    'system_type' => $data['system_type'] ?? FlightSystemType::Manual,
                    'pnr' => $data['pnr'] ?? null,
                    'airline_name' => $data['airline_name'] ?? 'Manual',
                    'airline' => $data['airline'] ?? $data['airline_name'] ?? 'Manual',
                    'origin' => $data['origin'] ?? $data['from_airport'] ?? 'N/A',
                    'destination' => $data['destination'] ?? $data['to_airport'] ?? 'N/A',
                    'from_airport' => $data['from_airport'] ?? null,
                    'to_airport' => $data['to_airport'] ?? null,
                    'from_airport_id' => $data['from_airport_id'] ?? null,
                    'to_airport_id' => $data['to_airport_id'] ?? null,
                    'departure_date' => $data['departure_date'] ?? now()->toDateString(),
                    'return_date' => $data['return_date'] ?? null,
                    'return_time' => $data['return_time'] ?? null,
                    'departure_time' => $data['departure_time'] ?? '00:00',
                    'arrival_time' => $data['arrival_time'] ?? null,
                    'trip_type' => $data['trip_type'] ?? 'one_way',
                    'passenger_count' => $data['passenger_count'] ?? $data['passengers_count'] ?? count($data['passengers'] ?? []) ?? 1,
                    'passengers_count' => $data['passengers_count'] ?? count($data['passengers'] ?? []) ?? 1,
                    'baggage_allowance_kg' => $data['baggage_allowance_kg'] ?? 0,
                    'trip_details' => $data['trip_details'] ?? null,
                    'purchase_price' => $purchasePriceEGP,
                    'selling_price' => $sellingPrice,
                    'profit' => $profit,
                    'currency' => $currency,
                    'foreign_currency' => $data['foreign_currency'] ?? null,
                    'purchase_price_foreign' => $data['purchase_price_foreign'] ?? null,
                    'exchange_rate' => $data['exchange_rate'] ?? 1.0,
                    'currency_used' => $settlementSnapshot['currency_used'],
                    'balance_currency_used' => $settlementSnapshot['balance_currency_used'],
                    'exchange_rate_used' => $settlementSnapshot['exchange_rate_used'],
                    'purchase_price_egp' => $purchasePriceEGP,
                    'flight_system_id' => $data['flight_system_id'] ?? null,
                    'flight_carrier_id' => $data['flight_carrier_id'] ?? null,
                    'flight_group_id' => $data['flight_group_id'] ?? null,
                    'purchase_balance_source' => $purchaseBalanceSource,
                    'status' => FlightBookingStatus::PENDING,
                    'account_id' => $data['account_id'] ?? null,
                    'airline_account_id' => $data['airline_account_id'] ?? null,
                    'agent_name' => $data['agent_name'] ?? 'Office',
                    'notes' => $data['notes'] ?? null,
                    'created_by' => $userId,
                ]);

                Log::info('Flight booking created', [
                    'flight_booking_id' => $booking->id,
                    'booking_number' => $booking->booking_number,
                    'purchase_price_egp' => $purchasePriceEGP,
                    'selling_price' => $sellingPrice,
                    'profit' => $profit,
                    'user_id' => $userId,
                ]);

                // Step 4: Debit exactly one balance pool (carrier or system) or credit a group account for purchase cost
                if ($purchaseBalanceSource === 'carrier' && ! empty($data['flight_carrier_id']) && $purchasePriceEGP > 0) {
                    $this->debitFlightCarrier(
                        $booking,
                        $data['flight_carrier_id'],
                        $purchasePriceEGP,
                        $currency,
                        $data['purchase_price_foreign'] ?? null,
                        $userId,
                        $lockCarrier
                    );
                }

                if ($purchaseBalanceSource === 'system' && ! empty($data['flight_system_id']) && $purchasePriceEGP > 0) {
                    $this->debitFlightSystem(
                        $booking,
                        (int) $data['flight_system_id'],
                        $purchasePriceEGP,
                        $currency,
                        isset($data['purchase_price_foreign']) ? (float) $data['purchase_price_foreign'] : null,
                        $userId,
                        $lockSystem
                    );
                }

                if ($purchaseBalanceSource === 'group' && ! empty($data['flight_group_id']) && $purchasePriceEGP > 0) {
                    $this->recordPurchaseFromGroup(
                        $booking,
                        (int) $data['flight_group_id'],
                        $purchasePriceEGP,
                        $userId
                    );
                }

                // Step 5: Create passengers (before ledger sale so statement descriptions include them)
                if (isset($data['passengers']) && is_array($data['passengers'])) {
                    $this->createPassengers($booking, $data['passengers']);
                }

                // Step 6: Always record sale on customer ledger (Debt)
                $this->recordSaleToCustomer(
                    $booking,
                    (int) $data['customer_id'],
                    $sellingPrice,
                    $userId,
                    $data['passengers'] ?? []
                );

                // Step 7: Tickets (after passengers so lines can link to passenger_id)
                $this->createFlightTickets($booking);

                // Step 8: Create flight segments
                if (isset($data['segments']) && is_array($data['segments'])) {
                    $this->createSegments($booking, $data['segments']);
                }

                // Step 9: Process initial payment if provided
                if (isset($data['payment']) && is_array($data['payment']) && ! empty($data['payment']['amount'])) {
                    $this->addPayment($booking, $data['payment']);
                }

                Log::info('Flight booking completed successfully', [
                    'flight_booking_id' => $booking->id,
                    'booking_number' => $booking->booking_number,
                    'user_id' => $userId,
                ]);

                return $booking->load([
                    'customer.ledgerAccount',
                    'employee.user',
                    'account',
                    'airlineAccount',
                    'flightSystem',
                    'flightCarrier.system',
                    'passengers',
                    'tickets',
                    'segments',
                    'payments.transaction',
                    'createdBy',
                ]);
            });
        } catch (\Exception $e) {
            Log::error('FlightBookingService::createBooking failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'user_id' => Auth::id(),
                'input' => $data,
            ]);
            throw new \Exception('فشل إنشاء الحجز: '.$e->getMessage());
        }
    }

    /**
     * Generate unique booking number
     */
    protected function generateBookingNumber(): string
    {
        $timestamp = now()->format('Ymd');
        $random = strtoupper(substr(md5(uniqid(mt_rand(), true)), 0, 6));

        return "{$timestamp}-{$random}";
    }

    /**
     * Normalize incoming payload (IDs → IATA, default segment, purchase_price alias).
     */
    protected function prepareFlightBookingPayload(array $data): array
    {
        if (empty($data['purchase_price']) && isset($data['purchase_price_egp'])) {
            $data['purchase_price'] = $data['purchase_price_egp'];
        }

        if (! empty($data['from_airport_id'])) {
            $from = Airport::query()->find($data['from_airport_id']);
            if ($from) {
                $data['from_airport'] = $data['from_airport'] ?? $from->iata_code;
                $data['origin'] = $data['origin'] ?? trim(($from->city_name_ar ?? '').' — '.($from->airport_name_ar ?? ''));
            }
        }

        if (! empty($data['to_airport_id'])) {
            $to = Airport::query()->find($data['to_airport_id']);
            if ($to) {
                $data['to_airport'] = $data['to_airport'] ?? $to->iata_code;
                $data['destination'] = $data['destination'] ?? trim(($to->city_name_ar ?? '').' — '.($to->airport_name_ar ?? ''));
            }
        }

        if (! empty($data['flight_carrier_id']) && empty($data['airline_name'])) {
            $carrier = FlightCarrier::query()->find($data['flight_carrier_id']);
            if ($carrier) {
                $data['airline_name'] = $carrier->name;
                $data['airline'] = $carrier->name;
            }
        }

        if (isset($data['segments']) && is_array($data['segments'])) {
            $data['segments'] = array_values(array_filter($data['segments'], function ($s): bool {
                if (! is_array($s)) {
                    return false;
                }
                foreach (['flight_number', 'flightNumber', 'from_airport', 'from', 'fromAirport', 'to_airport', 'to', 'toAirport', 'departure_date', 'departureDate'] as $k) {
                    if (! empty($s[$k])) {
                        return true;
                    }
                }

                return false;
            }));
        }

        if (empty($data['segments']) && ! empty($data['from_airport']) && ! empty($data['to_airport'])) {
            $data['segments'] = [[
                'airline_name' => $data['airline_name'] ?? '—',
                'flight_number' => $data['flight_number'] ?? 'TBA',
                'from_airport' => $data['from_airport'],
                'to_airport' => $data['to_airport'],
                'departure_date' => $data['departure_date'] ?? null,
                'departure_time' => $data['departure_time'] ?? '00:00:00',
                'arrival_time' => $data['arrival_time'] ?? '00:00:00',
                'flight_class' => $data['cabin_class'] ?? $data['flight_class'] ?? 'economy',
                'baggage_allowance' => $data['baggage_allowance_kg'] ?? null,
            ]];
        }

        $initial = isset($data['initial_payment']) ? (float) $data['initial_payment'] : 0.0;
        $existingPaymentAmount = isset($data['payment']['amount']) ? (float) $data['payment']['amount'] : 0.0;
        if ($initial > 0 && $existingPaymentAmount <= 0) {
            $data['payment'] = [
                'amount' => $initial,
                'payment_method' => $data['payment_method'] ?? 'cash',
                'account_id' => $data['account_id'] ?? null,
                'notes' => is_array($data['payment'] ?? null) ? ($data['payment']['notes'] ?? null) : null,
            ];
        }

        if (! empty($data['payment']['amount']) && (float) $data['payment']['amount'] > 0) {
            $data['payment']['payment_method'] = $data['payment']['payment_method']
                ?? $data['payment']['method']
                ?? $data['payment_method']
                ?? 'cash';
            $data['payment']['account_id'] = $data['payment']['account_id'] ?? $data['account_id'] ?? null;
        }

        return $data;
    }

    /**
     * When the API sends "" the FormRequest normalizes to null; do not write null into NOT NULL columns.
     */
    protected function shouldPreserveBookingFieldOnEmptyUpdate(string $key, mixed $value): bool
    {
        if ($value !== null && $value !== '') {
            return false;
        }

        return in_array($key, [
            'departure_time',
            'departure_date',
            'trip_type',
            'agent_name',
        ], true);
    }

    /**
     * سعر الصرف المحفوظ للعملة: عدد وحدات الجنيه المصري لكل 1 وحدة من العملة الأجنبية (مثل إعدادات العملات في Filament).
     */
    private function egpPerUnitOfCurrency(string $currencyCode): float
    {
        $code = strtoupper(trim($currencyCode));
        if ($code === '' || $code === 'EGP') {
            return 1.0;
        }

        $row = Currency::query()
            ->where('is_active', true)
            ->whereRaw('upper(code) = ?', [$code])
            ->first();

        if ($row && (float) $row->exchange_rate > 0) {
            return (float) $row->exchange_rate;
        }

        $inactive = Currency::query()
            ->whereRaw('upper(code) = ?', [$code])
            ->orderByDesc('is_active')
            ->first();

        if ($inactive && (float) $inactive->exchange_rate > 0) {
            Log::warning('Flight booking: using currency row that may be inactive — update /admin/currencies', [
                'code' => $code,
                'currency_id' => $inactive->getKey(),
            ]);

            return (float) $inactive->exchange_rate;
        }

        if (isset(self::FALLBACK_EGP_PER_UNIT[$code])) {
            Log::warning('Flight booking: using built-in fallback EGP rate — add currency in admin', [
                'code' => $code,
                'rate' => self::FALLBACK_EGP_PER_UNIT[$code],
            ]);

            return self::FALLBACK_EGP_PER_UNIT[$code];
        }

        return 0.0;
    }

    /**
     * جنيه مصري لكل 1 وحدة من عملة الرصيد وقت الحجز (من الطلب أو جدول العملات).
     */
    private function lockedEgpPerBalanceUnit(string $balanceCurrency, string $bookingCurrency, array $data): ?float
    {
        $bal = strtoupper(trim($balanceCurrency));
        $book = strtoupper(trim($bookingCurrency));
        if ($bal === 'EGP') {
            return 1.0;
        }
        if ($bal === $book && $book !== 'EGP') {
            $r = (float) ($data['exchange_rate'] ?? 0);
            if ($r > 0) {
                return $r;
            }
            $live = $this->egpPerUnitOfCurrency($bal);

            return $live > 0 ? $live : null;
        }
        if ($book === 'EGP') {
            $live = $this->egpPerUnitOfCurrency($bal);

            return $live > 0 ? $live : null;
        }
        $live = $this->egpPerUnitOfCurrency($bal);

        return $live > 0 ? $live : null;
    }

    /**
     * لقطة تُحفظ على صف الحجز حسب مصدر خصم تكلفة الشراء (ساين أو نظام).
     *
     * @return array{currency_used: string, balance_currency_used: ?string, exchange_rate_used: ?float}
     */
    private function persistedSettlementSnapshot(
        array $data,
        string $bookingCurrency,
        float $purchasePriceEgp,
        ?string $purchaseBalanceSource
    ): array {
        $currencyUsed = strtoupper(trim($bookingCurrency));
        if ($purchasePriceEgp <= 0) {
            return ['currency_used' => $currencyUsed, 'balance_currency_used' => null, 'exchange_rate_used' => null];
        }

        $balanceCurrency = null;
        if ($purchaseBalanceSource === 'system' && ! empty($data['flight_system_id'])) {
            $s = FlightSystem::query()->find((int) $data['flight_system_id']);
            $balanceCurrency = $s ? strtoupper((string) $s->currency) : null;
        } elseif ($purchaseBalanceSource === 'carrier' && ! empty($data['flight_carrier_id'])) {
            $c = FlightCarrier::query()->find((int) $data['flight_carrier_id']);
            $balanceCurrency = $c ? strtoupper((string) $c->currency) : null;
        } elseif ($purchaseBalanceSource === 'both') {
            if (! empty($data['flight_carrier_id'])) {
                $c = FlightCarrier::query()->find((int) $data['flight_carrier_id']);
                $balanceCurrency = $c ? strtoupper((string) $c->currency) : null;
            }
            if ($balanceCurrency === null && ! empty($data['flight_system_id'])) {
                $s = FlightSystem::query()->find((int) $data['flight_system_id']);
                $balanceCurrency = $s ? strtoupper((string) $s->currency) : null;
            }
        } else {
            if (! empty($data['flight_carrier_id'])) {
                $c = FlightCarrier::query()->find((int) $data['flight_carrier_id']);
                $balanceCurrency = $c ? strtoupper((string) $c->currency) : null;
            }
            if ($balanceCurrency === null && ! empty($data['flight_system_id'])) {
                $s = FlightSystem::query()->find((int) $data['flight_system_id']);
                $balanceCurrency = $s ? strtoupper((string) $s->currency) : null;
            }
        }

        if ($balanceCurrency === null) {
            return ['currency_used' => $currencyUsed, 'balance_currency_used' => null, 'exchange_rate_used' => null];
        }

        $lock = $this->lockedEgpPerBalanceUnit($balanceCurrency, $currencyUsed, $data);
        $exchangeUsed = ($lock !== null && $lock > 0) ? round((float) $lock, 6) : null;

        return [
            'currency_used' => $currencyUsed,
            'balance_currency_used' => $balanceCurrency,
            'exchange_rate_used' => $exchangeUsed,
        ];
    }

    /**
     * مصدر خصم تكلفة التذكرة: رصيد الساين أو رصيد النظام (واحد فقط للحجوزات الجديدة).
     *
     * @return 'carrier'|'system'|null
     */
    private function resolvePurchaseBalanceSource(array $data): ?string
    {
        $explicit = isset($data['purchase_balance_source']) ? strtolower(trim((string) $data['purchase_balance_source'])) : '';
        $hasGroup = ! empty($data['flight_group_id']);
        $isGroupChannel = isset($data['booking_channel_type']) && strtoupper(trim($data['booking_channel_type'])) === 'GROUP';

        if ($explicit === 'group' || $isGroupChannel || $hasGroup) {
            if (empty($data['flight_group_id'])) {
                throw new \Exception('لا يمكن إتمام الحجز عبر مجموعة دون تحديد المجموعة.');
            }

            return 'group';
        }

        $hasCarrier = ! empty($data['flight_carrier_id']);
        $hasSystem = ! empty($data['flight_system_id']);

        if (in_array($explicit, ['carrier', 'system'], true)) {
            if ($explicit === 'carrier' && ! $hasCarrier) {
                throw new \Exception('لا يمكن خصم التكلفة من رصيد الساين دون تحديد شركة الطيران.');
            }
            if ($explicit === 'system' && ! $hasSystem) {
                throw new \Exception('لا يمكن خصم التكلفة من رصيد النظام دون تحديد نظام الحجز.');
            }

            return $explicit;
        }

        if ($hasCarrier && $hasSystem) {
            $def = strtolower((string) config('flight_accounting.purchase_balance_default_when_both', 'carrier'));

            return in_array($def, ['carrier', 'system'], true) ? $def : 'carrier';
        }

        if ($hasCarrier) {
            return 'carrier';
        }

        if ($hasSystem) {
            return 'system';
        }

        return null;
    }

    private function lockForEntityDebit(array $data, string $which, string $bookingCurrency, float $purchasePriceEgp): ?float
    {
        if ($purchasePriceEgp <= 0) {
            return null;
        }
        if ($which === 'carrier') {
            if (empty($data['flight_carrier_id'])) {
                return null;
            }
            $c = FlightCarrier::query()->find((int) $data['flight_carrier_id']);
            if (! $c) {
                return null;
            }

            return $this->lockedEgpPerBalanceUnit(strtoupper((string) $c->currency), $bookingCurrency, $data);
        }
        if ($which === 'system') {
            if (empty($data['flight_system_id'])) {
                return null;
            }
            $s = FlightSystem::query()->find((int) $data['flight_system_id']);
            if (! $s) {
                return null;
            }

            return $this->lockedEgpPerBalanceUnit(strtoupper((string) $s->currency), $bookingCurrency, $data);
        }

        return null;
    }

    /**
     * سعر الصرف المحفوظ على الحجز إن طابقت عملة كيان الإرجاع لقطة الرصيد.
     */
    private function lockedRateFromBookingSnapshot(FlightBooking $booking, string $entityBalanceCurrency): ?float
    {
        $entity = strtoupper(trim($entityBalanceCurrency));
        $snap = strtoupper(trim((string) ($booking->balance_currency_used ?? '')));
        if ($snap === '' || $entity !== $snap) {
            return null;
        }
        $r = $booking->exchange_rate_used;
        if ($r === null || (float) $r <= 0) {
            return null;
        }

        return (float) $r;
    }

    /**
     * مبلغ خصم/إيداع رصيد شركة الطيران أو نظام الحجز بعملة ذلك الرصيد.
     *
     * @param  string  $balanceCurrency  عملة رصيد الشركة أو النظام (مثل KWD)
     * @param  string  $bookingCurrency  عملة تسعير المورد في الحجز (EGP أو نفس عملة الرصيد)
     * @param  float|null  $lockedEgpPerBalanceUnit  لقطة وقت الحجز (جنيه/وحدة رصيد) — يُفضّل عند الإلغاء
     */
    private function purchaseAmountInBalanceCurrency(
        string $balanceCurrency,
        string $bookingCurrency,
        float $purchasePriceEGP,
        ?float $purchasePriceForeign,
        ?float $lockedEgpPerBalanceUnit = null
    ): float {
        $bal = strtoupper(trim($balanceCurrency));
        $book = strtoupper(trim($bookingCurrency));

        if ($bal === 'EGP') {
            return round($purchasePriceEGP, 2);
        }

        if ($bal === $book && $book !== 'EGP') {
            return round((float) ($purchasePriceForeign ?? 0), 4);
        }

        if ($book === 'EGP') {
            $rate = ($lockedEgpPerBalanceUnit !== null && $lockedEgpPerBalanceUnit > 0)
                ? $lockedEgpPerBalanceUnit
                : $this->egpPerUnitOfCurrency($bal);
            if ($rate <= 0) {
                throw new \Exception(
                    "لا يوجد سعر صرف فعّال في جدول العملات للعملة {$bal} (جنيه لكل 1 {$bal}). حدّث سعر الصرف في الإدارة أو طابق عملة الشركة مع التسعير."
                );
            }

            return round($purchasePriceEGP / $rate, 4);
        }

        throw new \Exception(
            "عملة رصيد الشركة/النظام ({$bal}) لا تتوافق مع عملة تسعير الحجز ({$book}). استخدم نفس العملة أو التسعير بالجنيه مع سعر صرف مُعرَّف لـ {$bal}."
        );
    }

    /**
     * Debit flight carrier for ticket cost
     */
    protected function debitFlightCarrier(
        FlightBooking $booking,
        int $carrierId,
        float $purchasePriceEGP,
        string $currency,
        ?float $purchasePriceForeign,
        int $userId,
        ?float $lockedEgpPerBalanceUnit = null
    ): void {
        $carrier = FlightCarrier::lockForUpdate()->findOrFail($carrierId);

        $debitAmount = $this->purchaseAmountInBalanceCurrency(
            (string) $carrier->currency,
            $currency,
            $purchasePriceEGP,
            $purchasePriceForeign,
            $lockedEgpPerBalanceUnit
        );

        // Check balance
        if ($carrier->available_balance < $debitAmount) {
            throw new \Exception(
                'رصيد شركة الطيران غير كافٍ. '.
                "المطلوب: {$debitAmount} {$carrier->currency}، ".
                "المتاح: {$carrier->available_balance} {$carrier->currency}"
            );
        }

        // Debit the carrier
        $carrier->debit(
            amount: $debitAmount,
            bookingId: $booking->id,
            userId: $userId
        );

        $this->prepaidLedgerService->consumeCogs(
            prepaidKey: 'flight_carrier',
            module: TransactionModule::Flight,
            amount: $debitAmount,
            notes: sprintf('تكلفة حجز %s — ناقل %s', $booking->booking_number, $carrier->name),
            relatedType: FlightBooking::class,
            relatedId: $booking->id,
        );

        Log::info('Flight carrier debited', [
            'flight_booking_id' => $booking->id,
            'carrier_id' => $carrier->id,
            'amount' => $debitAmount,
            'currency' => $carrier->currency,
            'amount_egp' => $purchasePriceEGP,
            'balance_after' => $carrier->fresh()->available_balance,
            'user_id' => $userId,
        ]);
    }

    /**
     * Debit flight system (GDS/NDC balance pool) — نفس منطق عملة شركة الطيران.
     */
    protected function debitFlightSystem(
        FlightBooking $booking,
        int $systemId,
        float $purchasePriceEGP,
        string $currency,
        ?float $purchasePriceForeign,
        int $userId,
        ?float $lockedEgpPerBalanceUnit = null
    ): void {
        $system = FlightSystem::query()->lockForUpdate()->findOrFail($systemId);

        $debitAmount = $this->purchaseAmountInBalanceCurrency(
            (string) $system->currency,
            $currency,
            $purchasePriceEGP,
            $purchasePriceForeign,
            $lockedEgpPerBalanceUnit
        );

        if ($debitAmount <= 0) {
            return;
        }

        $system->debit(
            amount: $debitAmount,
            bookingId: $booking->id,
            userId: $userId
        );

        $this->prepaidLedgerService->consumeCogs(
            prepaidKey: 'flight_system',
            module: TransactionModule::Flight,
            amount: $debitAmount,
            notes: sprintf('تكلفة حجز %s — نظام %s', $booking->booking_number, $system->name),
            relatedType: FlightBooking::class,
            relatedId: $booking->id,
        );

        Log::info('Flight system debited', [
            'flight_booking_id' => $booking->id,
            'flight_system_id' => $system->id,
            'amount' => $debitAmount,
            'currency' => $system->currency,
            'balance_after' => $system->fresh()->available_balance,
            'user_id' => $userId,
        ]);
    }

    /**
     * Credit treasury account for selling price (balanced journal when clearing account exists).
     */
    protected function creditTreasuryAccount(
        FlightBooking $booking,
        int $accountId,
        float $sellingPrice,
        int $userId
    ): void {
        try {
            $contraId = $this->flightLedgerContraAccountId();

            if ($contraId !== null && $contraId !== $accountId) {
                $tx = $this->transactionService->recordJournalTransfer([
                    'amount' => $sellingPrice,
                    'from_account_id' => $contraId,
                    'to_account_id' => $accountId,
                    'allow_from_negative' => true,
                    'module' => TransactionModule::Flight->value,
                    'related_type' => FlightBooking::class,
                    'related_id' => $booking->id,
                    'notes' => 'بيع تذكرة طيران — حجز #'.$booking->booking_number,
                    'created_by' => $userId,
                ]);

                $booking->forceFill(['sale_gl_transaction_id' => $tx->id])->save();

                TreasuryLedgerMirror::mirrorFlightInboundReceipt(
                    $tx,
                    $booking->id,
                    'بيع تذكرة طيران — حجز #'.$booking->booking_number.' (مرآة الخزينة من الدفتر)',
                    User::query()->find($userId)?->name ?? 'System',
                );

                Log::info('Treasury credited (GL journal) for flight booking', [
                    'flight_booking_id' => $booking->id,
                    'account_id' => $accountId,
                    'contra_account_id' => $contraId,
                    'transaction_id' => $tx->id,
                    'amount' => $sellingPrice,
                    'user_id' => $userId,
                ]);

                return;
            }

            $this->treasuryService->credit(
                $accountId,
                $sellingPrice,
                'بيع تذكرة طيران - حجز #'.$booking->booking_number,
                $booking->id,
                $userId
            );

            Log::info('Treasury credited for flight booking', [
                'flight_booking_id' => $booking->id,
                'account_id' => $accountId,
                'amount' => $sellingPrice,
                'user_id' => $userId,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to credit treasury account', [
                'flight_booking_id' => $booking->id,
                'account_id' => $accountId,
                'amount' => $sellingPrice,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    protected function flightLedgerContraAccountId(): ?int
    {
        $configured = config('flight_accounting.ledger_clearing_account_id');
        if ($configured !== null && $configured !== '') {
            $id = (int) $configured;
            if (Account::query()->where('id', $id)->where('is_active', true)->exists()) {
                return $id;
            }
        }

        return $this->ledgerClearingAccounts->incomeContraIdForFlightBooking();
    }

    /**
     * يضمن وجود حساب إقفال إيرادات الطيران قبل تسجيل مديونية العميل.
     */
    protected function ensureFlightIncomeClearingAccount(int $userId): int
    {
        $existing = $this->flightLedgerContraAccountId();
        if ($existing !== null) {
            return $existing;
        }

        $name = config('flight_accounting.ledger_clearing_account_name')
            ?? config('accounting.clearing.income.flight')
            ?? 'إقفال مبيعات الطيران (نظام)';

        if (! is_string($name) || $name === '') {
            throw new \RuntimeException('تعذر تحديد اسم حساب إقفال مبيعات الطيران.');
        }

        return LedgerBalanceMutationGuard::run(fn () => (int) DB::transaction(function () use ($name, $userId) {
            $account = Account::query()->firstOrCreate(
                ['name' => $name],
                [
                    'type' => AccountType::Cashbox,
                    'balance' => 0,
                    'currency' => 'EGP',
                    'is_active' => true,
                    'owner_type' => Account::OWNER_TYPE_OWNER,
                    'module_type' => 'office',
                    'is_module_vault' => false,
                    'notes' => 'حساب إقفال إيرادات مبيعات الطيران (نظام)',
                    'created_by' => $userId,
                ]
            );

            Log::info('Flight income clearing account provisioned', [
                'account_id' => $account->id,
                'name' => $account->name,
            ]);

            return $account->id;
        }));
    }

    /**
     * إصلاح حجوزات سابقة لم يُسجَّل عليها قيد بيع العميل (مديونية التذكرة).
     *
     * @return array{repaired:int, skipped:int, errors:array<int,string>}
     */
    public function backfillMissingCustomerSaleLedgers(?int $limit = null): array
    {
        $stats = ['repaired' => 0, 'skipped' => 0, 'errors' => []];

        $query = FlightBooking::query()
            ->whereNull('sale_gl_transaction_id')
            ->where('selling_price', '>', 0)
            ->whereNotIn('status', [FlightBookingStatus::CANCELLED, FlightBookingStatus::REFUNDED])
            ->orderBy('id');

        if ($limit !== null) {
            $query->limit($limit);
        }

        foreach ($query->get() as $booking) {
            try {
                DB::transaction(function () use ($booking, &$stats): void {
                    $booking->refresh();
                    if ($booking->sale_gl_transaction_id !== null || (float) $booking->selling_price <= 0) {
                        $stats['skipped']++;

                        return;
                    }

                    $this->recordSaleToCustomer(
                        $booking,
                        (int) $booking->customer_id,
                        (float) $booking->selling_price,
                        (int) ($booking->created_by ?? Auth::id() ?? 1),
                    );

                    $stats['repaired']++;
                });
            } catch (\Throwable $e) {
                $stats['errors'][(int) $booking->id] = $e->getMessage();
                Log::warning('flight_sale_ledger_backfill_failed', [
                    'flight_booking_id' => $booking->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $stats;
    }

    /**
     * Reverse the sale journal (clearing → customer) when the booking was settled only via GL (no cash payments).
     */
    protected function reverseFlightBookingSaleLedger(FlightBooking $booking, int $userId): void
    {
        if (! $booking->sale_gl_transaction_id) {
            return;
        }

        $orig = Transaction::query()->find($booking->sale_gl_transaction_id);
        if (! $orig || ! $orig->from_account_id || ! $orig->to_account_id) {
            return;
        }

        $clearingId = (int) $orig->from_account_id;
        $customerAccountId = (int) $orig->to_account_id;
        $amount = (float) $orig->amount;

        $this->transactionService->recordJournalTransfer([
            'amount' => $amount,
            'from_account_id' => $customerAccountId,
            'to_account_id' => $clearingId,
            'allow_from_negative' => true,
            'module' => TransactionModule::Flight->value,
            'related_type' => FlightBooking::class,
            'related_id' => $booking->id,
            'notes' => 'إلغاء بيع تذكرة طيران — حجز #'.$booking->booking_number,
            'created_by' => $userId,
        ]);

        $booking->forceFill(['sale_gl_transaction_id' => null])->save();

        Log::info('Flight booking sale GL reversed', [
            'flight_booking_id' => $booking->id,
            'amount' => $amount,
            'user_id' => $userId,
        ]);
    }

    protected function createFlightTickets(FlightBooking $booking): void
    {
        $booking->load('passengers');

        if ($booking->passengers->isEmpty()) {
            FlightTicket::create([
                'flight_booking_id' => $booking->id,
                'passenger_id' => null,
                'ticket_number' => $this->generateTicketNumber($booking, null),
                'status' => 'issued',
            ]);

            return;
        }

        foreach ($booking->passengers as $passenger) {
            FlightTicket::create([
                'flight_booking_id' => $booking->id,
                'passenger_id' => $passenger->id,
                'ticket_number' => $this->generateTicketNumber($booking, (int) $passenger->id),
                'status' => 'issued',
            ]);
        }

        Log::info('Flight tickets created', [
            'flight_booking_id' => $booking->id,
            'count' => $booking->passengers->count(),
        ]);
    }

    protected function generateTicketNumber(FlightBooking $booking, ?int $passengerId): string
    {
        $suffix = $passengerId !== null ? (string) $passengerId : 'GRP';

        return 'TKT-'.$booking->id.'-'.$suffix.'-'.strtoupper(substr(md5((string) microtime(true)), 0, 8));
    }

    /**
     * Create passengers for booking
     */
    protected function createPassengers(FlightBooking $booking, array $passengers): void
    {
        foreach ($passengers as $passengerData) {
            $firstName = $passengerData['first_name'] ?? $passengerData['name'] ?? 'Unknown';
            $lastName = $passengerData['last_name'] ?? '';

            FlightPassenger::create([
                'flight_booking_id' => $booking->id,
                'type' => $passengerData['type'] ?? $passengerData['passenger_type'] ?? 'adult',
                'first_name' => $firstName,
                'last_name' => $lastName,
                'first_name_en' => $passengerData['first_name_en'] ?? $firstName,
                'last_name_en' => $passengerData['last_name_en'] ?? $lastName,
                'date_of_birth' => $passengerData['date_of_birth'] ?? null,
                'passport_number' => $passengerData['passport_number'] ?? null,
                'national_id' => $passengerData['national_id'] ?? null,
                'baggage_allowance_kg' => $passengerData['baggage_allowance_kg'] ?? 0,
                'nationality' => $passengerData['nationality'] ?? null,
            ]);
        }

        Log::info('Passengers created', [
            'flight_booking_id' => $booking->id,
            'count' => count($passengers),
        ]);
    }

    /**
     * Create flight segments
     */
    protected function createSegments(FlightBooking $booking, array $segments): void
    {
        foreach ($segments as $segmentData) {
            if (! is_array($segmentData)) {
                continue;
            }

            $from = $segmentData['from_airport'] ?? $segmentData['from'] ?? $segmentData['fromAirport'] ?? $booking->from_airport;
            $to = $segmentData['to_airport'] ?? $segmentData['to'] ?? $segmentData['toAirport'] ?? $booking->to_airport;
            $depRaw = $segmentData['departure_date'] ?? $segmentData['departureDate'] ?? $booking->departure_date;
            $depDate = $this->normalizeSegmentDateValue($depRaw) ?? $booking->departure_date?->format('Y-m-d');

            $flightNumber = $segmentData['flight_number'] ?? $segmentData['flightNumber'] ?? 'TBA';
            $flightNumber = is_string($flightNumber) && trim($flightNumber) !== '' ? trim($flightNumber) : 'TBA';

            $airline = $segmentData['airline_name'] ?? $segmentData['airline'] ?? $booking->airline_name ?? $booking->airline ?? '—';

            $depTime = $this->normalizeSegmentTimeValue(
                $segmentData['departure_time'] ?? $segmentData['departureTime'] ?? $booking->departure_time
            );
            $arrTime = $this->normalizeSegmentTimeValue(
                $segmentData['arrival_time'] ?? $segmentData['arrivalTime'] ?? $booking->arrival_time
            );

            if ($from === null || $to === null || $depDate === null) {
                throw new \InvalidArgumentException(
                    'بيانات مسار الرحلة ناقصة (مطار المغادرة / الوصول / تاريخ المغادرة). أكمل الخطوة 2 أو أضف شريحة رحلة كاملة.'
                );
            }

            FlightSegment::create([
                'flight_booking_id' => $booking->id,
                'airline' => $airline,
                'flight_number' => $flightNumber,
                'from_airport' => $from,
                'to_airport' => $to,
                'departure_date' => $depDate,
                'departure_time' => $depTime,
                'arrival_time' => $arrTime,
                'baggage' => $segmentData['baggage_allowance'] ?? $segmentData['baggage'] ?? null,
                'flight_class' => $segmentData['flight_class'] ?? $segmentData['flightClass'] ?? 'economy',
                'duration_minutes' => $segmentData['duration_minutes'] ?? null,
                'is_stop' => $segmentData['is_stop'] ?? false,
                'stop_duration_minutes' => $segmentData['stop_duration_minutes'] ?? null,
                'notes' => $segmentData['notes'] ?? null,
            ]);
        }

        Log::info('Flight segments created', [
            'flight_booking_id' => $booking->id,
            'count' => count($segments),
        ]);
    }

    private function normalizeSegmentTimeValue(mixed $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('H:i:s');
        }
        if ($value === null || $value === '') {
            return '00:00:00';
        }
        $s = trim((string) $value);
        if ($s === '') {
            return '00:00:00';
        }
        try {
            if (preg_match('/^\d{4}-\d{2}-\d{2}/', $s)) {
                return Carbon::parse($s)->format('H:i:s');
            }

            return Carbon::parse('2000-01-01 '.$s)->format('H:i:s');
        } catch (\Throwable) {
            return '00:00:00';
        }
    }

    private function normalizeSegmentDateValue(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }
        if ($value === null || $value === '') {
            return null;
        }
        try {
            return Carbon::parse((string) $value)->format('Y-m-d');
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Update booking details. Structural and pricing changes are restricted to PENDING bookings.
     *
     * @param  array  $data  Validated data
     *
     * @throws \Exception
     */
    public function updateBooking(FlightBooking $booking, array $data): FlightBooking
    {
        try {
            return DB::transaction(function () use ($booking, $data) {
                unset($data['payment'], $data['initial_payment']);
                $data = $this->prepareFlightBookingPayload($data);
                $booking->refresh();
                $pending = $booking->status === FlightBookingStatus::PENDING;

                $updates = [];

                foreach (['airline_name', 'pnr', 'trip_details', 'notes', 'agent_name', 'trip_type'] as $key) {
                    if (! array_key_exists($key, $data)) {
                        continue;
                    }
                    if ($this->shouldPreserveBookingFieldOnEmptyUpdate($key, $data[$key])) {
                        continue;
                    }
                    $updates[$key] = $data[$key];
                }

                if (array_key_exists('system_type', $data) && $data['system_type'] !== null && $data['system_type'] !== '') {
                    $try = FlightSystemType::tryFrom((string) $data['system_type']);
                    $updates['system_type'] = $try ?? $booking->system_type;
                }

                foreach (['from_airport', 'to_airport', 'departure_date', 'return_date', 'return_time', 'departure_time', 'arrival_time', 'baggage_allowance_kg'] as $key) {
                    if (! array_key_exists($key, $data)) {
                        continue;
                    }
                    if ($this->shouldPreserveBookingFieldOnEmptyUpdate($key, $data[$key])) {
                        continue;
                    }
                    $updates[$key] = $data[$key];
                }

                if ($pending) {
                    foreach ([
                        'customer_id',
                        'employee_id',
                        'flight_system_id',
                        'flight_carrier_id',
                        'flight_group_id',
                        'purchase_balance_source',
                        'from_airport_id',
                        'to_airport_id',
                        'account_id',
                        'currency',
                        'airline',
                    ] as $key) {
                        if (array_key_exists($key, $data)) {
                            $updates[$key] = $data[$key];
                        }
                    }

                    if (empty($data['purchase_price']) && isset($data['purchase_price_egp'])) {
                        $data['purchase_price'] = $data['purchase_price_egp'];
                    }

                    $currency = $data['currency'] ?? $booking->currency;
                    $purchasePriceEGP = (float) ($booking->purchase_price ?? 0);

                    if (array_key_exists('selling_price', $data)) {
                        $updates['selling_price'] = (float) $data['selling_price'];
                    }

                    if (array_key_exists('purchase_price', $data) || array_key_exists('purchase_price_egp', $data) || array_key_exists('purchase_price_foreign', $data)) {
                        if ($currency === 'EGP') {
                            $purchasePriceEGP = (float) ($data['purchase_price'] ?? $data['purchase_price_egp'] ?? $purchasePriceEGP);
                        } else {
                            $pf = (float) ($data['purchase_price_foreign'] ?? $booking->purchase_price_foreign ?? 0);
                            $rate = (float) ($data['exchange_rate'] ?? $booking->exchange_rate ?? 1.0);
                            $purchasePriceEGP = $pf * $rate;
                            $updates['purchase_price_foreign'] = $pf;
                            $updates['exchange_rate'] = $rate;
                        }
                        $updates['purchase_price'] = $purchasePriceEGP;
                        $updates['purchase_price_egp'] = $purchasePriceEGP;
                    }

                    if (array_key_exists('currency', $data)) {
                        $updates['currency'] = $currency;
                    }

                    $sellAfter = (float) ($updates['selling_price'] ?? $booking->selling_price);
                    $purchaseAfter = (float) ($updates['purchase_price'] ?? $booking->purchase_price);
                    if (array_key_exists('selling_price', $updates) || array_key_exists('purchase_price', $updates)) {
                        $updates['profit'] = $sellAfter - $purchaseAfter;
                    }

                    if (isset($data['passengers']) && is_array($data['passengers']) && count($data['passengers']) > 0) {
                        $booking->passengers()->delete();
                        FlightTicket::query()->where('flight_booking_id', $booking->id)->delete();
                        $this->createPassengers($booking, $data['passengers']);
                        $n = count($data['passengers']);
                        $updates['passenger_count'] = $n;
                        $this->createFlightTickets($booking);
                    }

                    if (isset($data['segments']) && is_array($data['segments'])) {
                        $booking->segments()->delete();
                        $this->createSegments($booking, $data['segments']);
                    }
                }

                if ($updates !== []) {
                    $booking->update($updates);
                }

                Log::info('Flight booking updated', [
                    'flight_booking_id' => $booking->id,
                    'pending' => $pending,
                    'user_id' => Auth::id(),
                ]);

                return $booking->fresh([
                    'customer',
                    'employee.user',
                    'account',
                    'flightSystem',
                    'flightCarrier.system',
                    'flightGroup',
                    'passengers',
                    'tickets',
                    'segments',
                    'payments.transaction',
                    'payments.account',
                    'refund.transaction',
                    'createdBy',
                ]);
            });
        } catch (\Exception $e) {
            Log::error('FlightBookingService::updateBooking failed', [
                'error' => $e->getMessage(),
                'user_id' => Auth::id(),
                'flight_booking_id' => $booking->id,
                'input' => $data,
            ]);
            throw $e;
        }
    }

    /**
     * Update purchase and selling prices.
     * Only allowed if booking status is pending.
     * Recomputes profit.
     *
     * @throws \Exception
     */
    public function updatePrices(FlightBooking $booking, float $purchasePrice, float $sellingPrice): FlightBooking
    {
        if ($booking->status !== FlightBookingStatus::PENDING) {
            throw new \Exception('Only pending bookings can have prices updated.');
        }

        try {
            $profit = $sellingPrice - $purchasePrice;

            $booking->update([
                'purchase_price' => $purchasePrice,
                'selling_price' => $sellingPrice,
                'profit' => $profit,
            ]);

            Log::info('Flight booking prices updated', [
                'flight_booking_id' => $booking->id,
                'purchase_price' => $purchasePrice,
                'selling_price' => $sellingPrice,
                'profit' => $profit,
                'user_id' => Auth::id(),
            ]);

            return $booking->fresh([
                'customer',
                'employee.user',
                'account',
                'flightSystem',
                'flightCarrier.system',
                'flightGroup',
                'passengers',
                'tickets',
                'segments',
                'payments.transaction',
                'payments.account',
                'refund.transaction',
                'createdBy',
            ]);
        } catch (\Exception $e) {
            Log::error('FlightBookingService::updatePrices failed', [
                'error' => $e->getMessage(),
                'user_id' => Auth::id(),
                'flight_booking_id' => $booking->id,
                'purchase_price' => $purchasePrice,
                'selling_price' => $sellingPrice,
            ]);
            throw $e;
        }
    }

    /**
     * Confirm a booking. Only allowed from pending status.
     *
     * @throws \Exception
     */
    public function confirmBooking(FlightBooking $booking): FlightBooking
    {
        if ($booking->status !== FlightBookingStatus::PENDING) {
            throw new \Exception('Only pending bookings can be confirmed.');
        }

        try {
            DB::transaction(function () use ($booking) {
                $booking->update(['status' => FlightBookingStatus::CONFIRMED]);

                Log::info('Flight booking confirmed', [
                    'flight_booking_id' => $booking->id,
                    'user_id' => Auth::id(),
                ]);
            });

            return $booking->fresh([
                'customer',
                'employee.user',
                'account',
                'flightSystem',
                'flightCarrier.system',
                'flightGroup',
                'passengers',
                'tickets',
                'segments',
                'payments.transaction',
                'payments.account',
                'refund.transaction',
                'createdBy',
            ]);
        } catch (\Exception $e) {
            Log::error('FlightBookingService::confirmBooking failed', [
                'error' => $e->getMessage(),
                'user_id' => Auth::id(),
                'flight_booking_id' => $booking->id,
            ]);
            throw $e;
        }
    }

    /**
     * Record a payment for a flight booking.
     * Rejects if status is cancelled or refunded.
     * Validates total payments do not exceed selling_price.
     *
     * @param  array  $data  Validated data (amount, account_id, notes)
     *
     * @throws \Exception
     */
    public function addPayment(FlightBooking $booking, array $data): FlightPayment
    {
        if (in_array($booking->status, [FlightBookingStatus::CANCELLED, FlightBookingStatus::REFUNDED])) {
            throw new \Exception('Cannot add payment to a cancelled or refunded booking.');
        }

        try {
            return DB::transaction(function () use ($booking, $data) {
                $amount = (float) $data['amount'];

                // Validate total payments won't exceed selling_price
                $totalPaid = $booking->payments()->sum('amount') ?? 0;
                if ($totalPaid + $amount > $booking->selling_price) {
                    throw new \Exception(
                        'Total payments would exceed selling price. '.
                        "Current: {$totalPaid}, Adding: {$amount}, Total: ".($totalPaid + $amount).
                        ", Selling: {$booking->selling_price}"
                    );
                }

                $accountId = (int) ($data['account_id'] ?? 0);
                if ($accountId === 0) {
                    $vault = Account::getModuleVault('flights');
                    $accountId = $vault ? $vault->id : 0;
                }
                if ($accountId === 0) {
                    throw new \Exception('يجب تحديد الحساب المالي أو ضبط الخزينة الرسمية لموديول الطيران.');
                }

                $customerAccount = $this->ensureCustomerAccount((int) $booking->customer_id);

                // تحصيل الدفعة من حساب العميل (تخفيض المديونية) إلى الخزينة
                // الإيراد مُسجَّل مسبقاً عند إنشاء الحجز في recordSaleToCustomer (clearing → customer)
                // هذا القيد محايد (neutral) — تحويل من مديونية → نقدية فقط
                $transaction = $this->transactionService->recordIncome([
                    'amount' => $amount,
                    'to_account_id' => $accountId,
                    'contra_account_id' => $customerAccount->id,
                    'module' => TransactionModule::Flight->value,
                    'related_type' => FlightBooking::class,
                    'related_id' => $booking->id,
                    'notes' => $data['notes'] ?? null,
                ]);

                $account = Account::query()->find($accountId);
                $treasuryLabel = $account
                    ? (string) $account->id.'|'.($account->name ?? '')
                    : (string) ($data['account_id'] ?? '');

                TreasuryLedgerMirror::mirrorFlightInboundReceipt(
                    $transaction,
                    $booking->id,
                    'تحصيل طيران — حجز #'.$booking->booking_number,
                    (string) (Auth::user()?->name ?? 'system'),
                    $treasuryLabel,
                );

                $payment = FlightPayment::create([
                    'flight_booking_id' => $booking->id,
                    'amount' => $amount,
                    'payment_method' => $data['payment_method'] ?? $data['method'] ?? FlightPaymentMethod::Cash->value,
                    'currency' => $booking->currency ?? 'EGP',
                    'treasury_account' => $treasuryLabel,
                    'transaction_reference' => (string) $transaction->id,
                    'payment_date' => now(),
                    'paid_by' => (string) (Auth::user()?->name ?? 'system'),
                    'account_id' => $data['account_id'],
                    'transaction_id' => $transaction->id,
                    'notes' => $data['notes'] ?? null,
                    'created_by' => Auth::id(),
                ]);

                Log::info('Flight payment recorded', [
                    'payment_id' => $payment->id,
                    'flight_booking_id' => $booking->id,
                    'amount' => $amount,
                    'transaction_id' => $transaction->id,
                    'user_id' => Auth::id(),
                ]);

                return $payment->load([
                    'booking',
                    'account',
                    'transaction',
                    'createdBy',
                ]);
            });
        } catch (\Exception $e) {
            Log::error('FlightBookingService::addPayment failed', [
                'error' => $e->getMessage(),
                'user_id' => Auth::id(),
                'flight_booking_id' => $booking->id,
                'input' => $data,
            ]);
            throw $e;
        }
    }

    /**
     * Cancel a booking with complete accounting rollback.
     *
     * Flow:
     * 1. Validate booking can be cancelled
     * 2. Calculate refund amount (total paid - penalties)
     * 3. Void issued flight tickets
     * 4. Credit back flight carrier (if applicable)
     * 5. Reverse GL sale journal when applicable (no recorded payments)
     * 6. Debit treasury for cash refunds (recorded payments)
     * 7. Create refund record and update booking status
     *
     * Rollback: All operations in single DB transaction
     *
     * @param  array  $data  Validated data (airline_penalty, office_penalty, account_id, notes)
     *
     * @throws \Exception
     */
    public function cancelBooking(FlightBooking $booking, array $data): FlightRefund
    {
        if (in_array($booking->status, [FlightBookingStatus::CANCELLED, FlightBookingStatus::REFUNDED])) {
            throw new \Exception('الحجز ملغي أو مسترد بالفعل');
        }

        try {
            return DB::transaction(function () use ($booking, $data) {
                $userId = Auth::id() ?: 1;

                // Step 1: Calculate refund
                $totalPaid = $booking->payments()->sum('amount') ?? 0;
                $airlinePenalty = (float) ($data['airline_penalty'] ?? 0);
                $officePenalty = (float) ($data['office_penalty'] ?? 0);

                if ($totalPaid > 0.001 && $airlinePenalty + $officePenalty > $totalPaid + 0.001) {
                    throw new \InvalidArgumentException('مجموع خصم الطيران وعمولة الإلغاء لا يمكن أن يتجاوز المبلغ المدفوع من العميل.');
                }

                $refundAmount = $totalPaid - $airlinePenalty - $officePenalty;
                if ($refundAmount < 0) {
                    $refundAmount = 0;
                }

                Log::info('Processing booking cancellation', [
                    'flight_booking_id' => $booking->id,
                    'booking_number' => $booking->booking_number,
                    'total_paid' => $totalPaid,
                    'airline_penalty' => $airlinePenalty,
                    'office_penalty' => $officePenalty,
                    'refund_amount' => $refundAmount,
                    'user_id' => $userId,
                ]);

                FlightTicket::query()
                    ->where('flight_booking_id', $booking->id)
                    ->where('status', 'issued')
                    ->update(['status' => 'cancelled']);

                // Step 2: Credit back purchase pool(s) — mirror createBooking (single source or legacy both)
                $src = $booking->purchase_balance_source;
                $legacyDual = $src === 'both'
                    || ($src === null
                        && $booking->flight_carrier_id
                        && $booking->flight_system_id
                        && (float) $booking->purchase_price > 0);

                if ($legacyDual) {
                    if ($booking->flight_carrier_id && (float) $booking->purchase_price > 0) {
                        $this->creditBackFlightCarrier($booking, $airlinePenalty);
                    }
                    if ($booking->flight_system_id && (float) $booking->purchase_price > 0) {
                        $this->creditBackFlightSystem($booking, $airlinePenalty);
                    }
                } elseif ($src === 'carrier' && $booking->flight_carrier_id && (float) $booking->purchase_price > 0) {
                    $this->creditBackFlightCarrier($booking, $airlinePenalty);
                } elseif ($src === 'system' && $booking->flight_system_id && (float) $booking->purchase_price > 0) {
                    $this->creditBackFlightSystem($booking, $airlinePenalty);
                } elseif ($src === 'group' && $booking->flight_group_id && (float) $booking->purchase_price > 0) {
                    $this->reverseGroupPurchase($booking, $airlinePenalty, $userId);
                } elseif ($src === null) {
                    if ($booking->flight_carrier_id && (float) $booking->purchase_price > 0) {
                        $this->creditBackFlightCarrier($booking, $airlinePenalty);
                    } elseif ($booking->flight_system_id && (float) $booking->purchase_price > 0) {
                        $this->creditBackFlightSystem($booking, $airlinePenalty);
                    } elseif ($booking->flight_group_id && (float) $booking->purchase_price > 0) {
                        $this->reverseGroupPurchase($booking, $airlinePenalty, $userId);
                    }
                }

                // Step 3: Reverse GL sale (only when no recorded payments; mixed cases need manual review)
                if ($booking->sale_gl_transaction_id) {
                    if ((float) $totalPaid > 0.01) {
                        Log::warning('Flight booking cancel: sale_gl_transaction present with payments; GL reversal skipped', [
                            'flight_booking_id' => $booking->id,
                            'total_paid' => $totalPaid,
                        ]);
                    } else {
                        $this->reverseFlightBookingSaleLedger($booking, $userId);
                    }
                }

                if ($refundAmount > 0 && empty($data['account_id'])) {
                    throw new \InvalidArgumentException('يجب اختيار حساب الصرف عند وجود مبلغ مرتجع للعميل.');
                }

                // Step 4: Cash refund from treasury (recorded payments)
                $refundLedgerTx = null;
                if ($refundAmount > 0 && ! empty($data['account_id'])) {
                    $refundLedgerTx = $this->refundTreasuryAccount(
                        $booking,
                        $data['account_id'],
                        $refundAmount,
                        $userId
                    );
                }

                // Step 5: Create refund record
                $refund = FlightRefund::create([
                    'flight_booking_id' => $booking->id,
                    'airline_penalty' => $airlinePenalty,
                    'office_penalty' => $officePenalty,
                    'total_paid' => $totalPaid,
                    'refund_amount' => $refundAmount,
                    'account_id' => $data['account_id'] ?? null,
                    'transaction_id' => $refundLedgerTx?->id,
                    'status' => $refundAmount > 0 ? 'processed' : 'no_refund',
                    'notes' => $data['notes'] ?? null,
                    'created_by' => $userId,
                ]);

                // Step 6: Update booking status
                $newStatus = $refundAmount > 0 ? FlightBookingStatus::REFUNDED : FlightBookingStatus::CANCELLED;
                $booking->update(['status' => $newStatus]);

                Log::info('Booking cancelled successfully', [
                    'flight_booking_id' => $booking->id,
                    'refund_id' => $refund->id,
                    'new_status' => $newStatus,
                    'user_id' => $userId,
                ]);

                return $refund->load([
                    'booking',
                    'account',
                    'createdBy',
                ]);
            });
        } catch (\Exception $e) {
            Log::error('FlightBookingService::cancelBooking failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'user_id' => Auth::id(),
                'flight_booking_id' => $booking->id,
            ]);
            throw new \Exception('فشل إلغاء الحجز: '.$e->getMessage());
        }
    }

    /**
     * Credit back to flight carrier (undo previous debit)
     */
    protected function creditBackFlightCarrier(
        FlightBooking $booking,
        float $airlinePenalty
    ): void {
        $carrier = FlightCarrier::lockForUpdate()->findOrFail($booking->flight_carrier_id);

        $purchaseEgp = (float) ($booking->purchase_price_egp ?? $booking->purchase_price);
        $netEgp = max(0.0, $purchaseEgp - (float) $airlinePenalty);

        // الجزاء يُفترض بالجنيه؛ صافي التكلفة بالجنيه ثم التحويل لعملة رصيد الشركة (مثل عند الخصم).
        $creditAmount = $this->purchaseAmountInBalanceCurrency(
            (string) $carrier->currency,
            'EGP',
            $netEgp,
            null,
            $this->lockedRateFromBookingSnapshot($booking, (string) $carrier->currency)
        );

        if ($creditAmount <= 0) {
            Log::info('No credit back to carrier (penalty >= purchase)', [
                'flight_booking_id' => $booking->id,
                'purchase_price' => $booking->purchase_price,
                'penalty' => $airlinePenalty,
            ]);

            return;
        }

        // Credit the carrier
        $carrier->credit(
            amount: $creditAmount,
            description: 'إلغاء حجز تذكرة - استرداد رصيد - حجز #'.$booking->booking_number,
            userId: Auth::id() ?: 1,
            bookingId: $booking->id
        );

        Log::info('Flight carrier credited back (booking cancelled)', [
            'flight_booking_id' => $booking->id,
            'carrier_id' => $carrier->id,
            'credit_amount' => $creditAmount,
            'penalty' => $airlinePenalty,
            'balance_after' => $carrier->fresh()->available_balance,
        ]);
    }

    /**
     * إرجاع رصيد نظام الحجز بعد الإلغاء (مع خصم جزاء الخطوط إن وُجد).
     */
    protected function creditBackFlightSystem(FlightBooking $booking, float $airlinePenalty): void
    {
        $system = FlightSystem::query()->lockForUpdate()->findOrFail($booking->flight_system_id);

        $purchaseEgp = (float) ($booking->purchase_price_egp ?? $booking->purchase_price);
        $netEgp = max(0.0, $purchaseEgp - (float) $airlinePenalty);

        $creditAmount = $this->purchaseAmountInBalanceCurrency(
            (string) $system->currency,
            'EGP',
            $netEgp,
            null,
            $this->lockedRateFromBookingSnapshot($booking, (string) $system->currency)
        );

        if ($creditAmount <= 0) {
            Log::info('No credit back to flight system (penalty >= purchase)', [
                'flight_booking_id' => $booking->id,
                'flight_system_id' => $system->id,
                'credit_amount' => $creditAmount,
                'penalty' => $airlinePenalty,
            ]);

            return;
        }

        $system->credit(
            amount: $creditAmount,
            description: 'إلغاء حجز — إرجاع رصيد نظام — حجز #'.$booking->booking_number,
            userId: Auth::id() ?: 1,
            bookingId: $booking->id
        );

        Log::info('Flight system credited back (booking cancelled)', [
            'flight_booking_id' => $booking->id,
            'flight_system_id' => $system->id,
            'credit_amount' => $creditAmount,
            'penalty' => $airlinePenalty,
            'balance_after' => $system->fresh()->available_balance,
        ]);
    }

    /**
     * Refund treasury account (undo previous credit)
     */
    protected function refundTreasuryAccount(
        FlightBooking $booking,
        int $accountId,
        float $refundAmount,
        int $userId
    ): Transaction {
        try {
            $transaction = $this->transactionService->recordExpense([
                'amount' => $refundAmount,
                'from_account_id' => $accountId,
                'module' => TransactionModule::Flight->value,
                'related_type' => FlightBooking::class,
                'related_id' => $booking->id,
                'notes' => "استرداد حجز تذكرة - {$booking->booking_number}",
            ]);

            TreasuryLedgerMirror::mirrorFlightOutboundFromCash(
                $transaction,
                $booking->id,
                "مرآة استرداد نقدي — حجز #{$booking->booking_number}",
                User::query()->find($userId)?->name ?? 'System',
            );

            Log::info('Treasury refunded for cancelled booking', [
                'flight_booking_id' => $booking->id,
                'account_id' => $accountId,
                'refund_amount' => $refundAmount,
                'transaction_id' => $transaction->id,
                'user_id' => $userId,
            ]);

            return $transaction;
        } catch (\Exception $e) {
            Log::error('Failed to refund treasury account', [
                'flight_booking_id' => $booking->id,
                'account_id' => $accountId,
                'refund_amount' => $refundAmount,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Get a single booking by ID with all relations.
     *
     * @throws ModelNotFoundException
     */
    public function getBookingById(int $id): FlightBooking
    {
        return FlightBooking::with([
            'customer',
            'employee.user',
            'account',
            'passengers',
            'tickets',
            'segments',
            'payments.transaction',
            'refund.transaction',
            'createdBy',
        ])->findOrFail($id);
    }

    /**
     * Soft delete a booking.
     * Only allowed if status is pending and no payments exist.
     *
     * @throws \Exception
     */
    public function deleteBooking(FlightBooking $booking): bool
    {
        if ($booking->status !== FlightBookingStatus::PENDING) {
            throw new \Exception('Only pending bookings can be deleted.');
        }

        if ($booking->payments()->exists()) {
            throw new \Exception('Cannot delete booking with existing payments.');
        }

        try {
            DB::transaction(function () use ($booking) {
                $booking->delete();

                Log::info('Flight booking deleted', [
                    'flight_booking_id' => $booking->id,
                    'user_id' => Auth::id(),
                ]);
            });

            return true;
        } catch (\Exception $e) {
            Log::error('FlightBookingService::deleteBooking failed', [
                'error' => $e->getMessage(),
                'user_id' => Auth::id(),
                'flight_booking_id' => $booking->id,
            ]);
            throw $e;
        }
    }

    /**
     * Ensures the customer has a ledger account. Creates one if missing.
     */
    protected function ensureCustomerAccount(int $customerId): Account
    {
        $customer = Customer::findOrFail($customerId);

        if ($customer->account_id) {
            $account = Account::find($customer->account_id);
            if ($account) {
                return $account;
            }
        }

        // Create new account for customer
        return LedgerBalanceMutationGuard::run(fn () => DB::transaction(function () use ($customer) {
            $account = Account::create([
                'name' => 'حساب العميل: '.$customer->full_name,
                'type' => AccountType::Customer,
                'balance' => 0,
                'currency' => 'EGP',
                'is_active' => true,
                'owner_type' => Account::OWNER_TYPE_OWNER,
                'module_type' => 'tourism',
                'is_module_vault' => false,
                'notes' => 'حساب تلقائي للعميل #'.$customer->id,
                'created_by' => Auth::id() ?? 1,
            ]);

            $customer->update(['account_id' => $account->id]);

            Log::info('Customer ledger account created automatically', [
                'customer_id' => $customer->id,
                'account_id' => $account->id,
            ]);

            return $account;
        }));
    }

    /**
     * Record the sale as a debt on the customer ledger.
     */
    protected function recordSaleToCustomer(FlightBooking $booking, int $customerId, float $sellingPrice, int $userId, array $passengers = []): void
    {
        if ($sellingPrice <= 0) {
            return;
        }

        $customerAccount = $this->ensureCustomerAccount($customerId);
        $clearingAccountId = $this->ensureFlightIncomeClearingAccount($userId);

        if ($clearingAccountId === $customerAccount->id) {
            throw new \RuntimeException('حساب إقفال مبيعات الطيران يطابق حساب العميل — لا يمكن تسجيل المديونية.');
        }

        $booking->loadMissing(['customer', 'passengers', 'fromAirport', 'toAirport']);
        $notes = app(LedgerEntryDescriptionResolver::class)->forFlightBooking($booking);

        $tx = $this->transactionService->recordJournalTransfer([
            'amount' => $sellingPrice,
            'from_account_id' => $clearingAccountId,
            'to_account_id' => $customerAccount->id,
            'allow_from_negative' => true,
            'module' => TransactionModule::Flight->value,
            'related_type' => FlightBooking::class,
            'related_id' => $booking->id,
            'notes' => $notes,
            'created_by' => $userId,
        ]);

        $booking->forceFill(['sale_gl_transaction_id' => $tx->id])->save();

        Log::info('Flight sale recorded on customer ledger', [
            'booking_id' => $booking->id,
            'customer_id' => $customerId,
            'account_id' => $customerAccount->id,
            'amount' => $sellingPrice,
        ]);
    }

    protected function recordPurchaseFromGroup(
        FlightBooking $booking,
        int $groupId,
        float $purchasePriceEGP,
        int $userId
    ): void {
        $group = FlightGroup::findOrFail($groupId);
        $carrier = $group->carrier;
        $groupCurrency = $carrier?->currency ?: 'EGP';

        $debitAmount = $this->purchaseAmountInBalanceCurrency(
            (string) $groupCurrency,
            $booking->foreign_currency ?: 'EGP',
            $purchasePriceEGP,
            $booking->purchase_price_foreign,
            $booking->exchange_rate
        );

        FlightGroupTransaction::create([
            'flight_group_id' => $group->id,
            'flight_booking_id' => $booking->id,
            'type' => 'debt',
            'amount' => $debitAmount,
            'notes' => 'شراء تذكرة طيران بالأجل — حجز #'.$booking->booking_number,
            'created_by' => $userId,
        ]);

        Log::info('Flight purchase from group recorded on group ledger', [
            'booking_id' => $booking->id,
            'group_id' => $groupId,
            'amount' => $debitAmount,
            'currency' => $groupCurrency,
        ]);
    }

    /**
     * Reverse group purchase ledger entry when booking is cancelled.
     */
    protected function reverseGroupPurchase(FlightBooking $booking, float $airlinePenalty, int $userId): void
    {
        $group = FlightGroup::find($booking->flight_group_id);
        if (! $group) {
            return;
        }

        $purchaseEgp = (float) ($booking->purchase_price_egp ?? $booking->purchase_price);
        $netReversalEgp = max(0.0, $purchaseEgp - (float) $airlinePenalty);

        if ($netReversalEgp <= 0) {
            Log::info('No reversal for group purchase (penalty >= purchase)', [
                'flight_booking_id' => $booking->id,
                'purchase_price' => $booking->purchase_price,
                'penalty' => $airlinePenalty,
            ]);

            return;
        }

        $carrier = $group->carrier;
        $groupCurrency = $carrier?->currency ?: 'EGP';

        $netReversal = $this->purchaseAmountInBalanceCurrency(
            (string) $groupCurrency,
            'EGP',
            $netReversalEgp,
            null,
            $this->lockedRateFromBookingSnapshot($booking, (string) $groupCurrency)
        );

        if ($netReversal <= 0) {
            return;
        }

        FlightGroupTransaction::create([
            'flight_group_id' => $group->id,
            'flight_booking_id' => $booking->id,
            'type' => 'payment',
            'amount' => $netReversal,
            'notes' => 'إلغاء شراء تذكرة طيران (إرجاع رصيد) — حجز #'.$booking->booking_number.' (غرامة: '.$airlinePenalty.')',
            'created_by' => $userId,
        ]);

        Log::info('Flight group purchase reversed (booking cancelled)', [
            'flight_booking_id' => $booking->id,
            'group_id' => $group->id,
            'reversal_amount' => $netReversal,
            'penalty' => $airlinePenalty,
            'currency' => $groupCurrency,
        ]);
    }
}
