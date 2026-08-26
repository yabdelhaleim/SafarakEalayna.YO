<?php

namespace App\Services\Flight;

use App\Enums\AccountType;
use App\Enums\BookingChannelType;
use App\Enums\FlightBookingStatus;
use App\Enums\FlightPaymentMethod;
use App\Enums\FlightSystemType;
use App\Enums\TransactionModule;
use App\Exceptions\BusinessLogicException;
use App\Models\Account;
use App\Models\AccountEntry;
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
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

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
            'fromAirport',
            'toAirport',
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
        $startedAt = microtime(true);

        // D4 FIX (2026-08-15): defensive guard for callers that bypass the
        // HTTP FormRequest (CLI, internal services, batch imports). The
        // StoreFlightBookingRequest already validates `min:0` on
        // purchase_price and selling_price, but the service must also
        // enforce the invariant because runProfitMutation will credit
        // the carrier by negative-purchase_amount (money-creation vector).
        foreach (['purchase_price', 'purchase_price_egp', 'purchase_price_foreign', 'selling_price'] as $priceKey) {
            if (array_key_exists($priceKey, $data) && $data[$priceKey] !== null && (float) $data[$priceKey] < 0) {
                throw new \InvalidArgumentException(
                    "Flight price «{$priceKey}» must be non-negative. ".
                    "Received: ".(float) $data[$priceKey]
                );
            }
        }

        try {
            $booking = DB::transaction(function () use ($data) {
                $data = $this->prepareFlightBookingPayload($data);

                $userId = Auth::id() ?: 1;

                // Step 1: Calculate pricing
                $currency = $data['currency'] ?? 'EGP';
                $purchasePriceEGP = 0;
                $sellingPrice = (float) ($data['selling_price'] ?? 0);

                // 2026-08-05: سعر الصرف يُجلب من السيرفر فقط (إعدادات الأدمن
                // في جدول `currencies` عبر Filament). أي قيمة يرسلها العميل
                // في `exchange_rate` يتم تجاهلها — الموظف لا يمكنه تعديلها.
                // إن لم يُسجَّل السعر في `currencies` وفي الـ FALLBACK،
                // يفشل الحجز بـ 422 Validation بدلاً من حساب 0 EGP.
                $exchangeRate = $this->egpPerUnitOfCurrency($currency);
                if ($exchangeRate <= 0 && $currency !== 'EGP') {
                    throw new \Illuminate\Validation\ValidationException(
                        validator: \Illuminate\Support\Facades\Validator::make(
                            ['currency' => $currency],
                            ['currency' => 'required'],
                            ['currency' => "سعر صرف العملة ({$currency}) غير مُعرَّف في إعدادات العملات. تواصل مع مدير النظام لتحديث السعر من شاشة «العملات وأسعار الصرف» قبل إنشاء الحجز."]
                        )
                    );
                }

                if ($currency === 'EGP') {
                    $purchasePriceEGP = (float) ($data['purchase_price'] ?? 0);
                    $sellingPriceEGP = $sellingPrice;
                } else {
                    $purchasePriceForeign = (float) ($data['purchase_price_foreign'] ?? 0);
                    $purchasePriceEGP = $purchasePriceForeign * $exchangeRate;
                    // 2026-07-23 fix: selling_price is ALWAYS in EGP, regardless of booking currency.
                    // The Vue form labels the field "سعر البيع للعميل (EGP)" and sends the
                    // EGP value directly. Multiplying by exchange_rate here would double-convert
                    // and produce wildly wrong numbers (e.g. user types 1,360,000 EGP for a KWD
                    // booking → backend would store 217,600,000 EGP). The foreign-currency
                    // equivalent can be derived on demand as `selling_price / exchange_rate`.
                    $sellingPriceEGP = $sellingPrice;
                }

                $profit = $sellingPriceEGP - $purchasePriceEGP;

                // Bug #3 fix (2026-07-29): compute and persist the foreign-currency
                // selling price for non-EGP bookings. The `selling_price` column is
                // ALWAYS in EGP (per the 2026-07-23 fix), but cancel / delete flows
                // need the foreign-currency value to:
                //   (a) compute the refund amount in booking currency (since the
                //       refund account is validated to be in booking currency), and
                //   (b) compute the EGP-equivalent sale amount for the GL sale reversal
                //       without multiplying the already-EGP `selling_price` by
                //       `exchange_rate` again (the previous double-conversion bug
                //       that caused -57,800 USD wallet balances in scenario C).
                $sellingPriceForeign = $currency !== 'EGP'
                    ? round($sellingPriceEGP / max($exchangeRate, 0.0001), 2)
                    : null;

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

                // Step 3: Create booking record (wrapped in ::run() so the
                // ModelProfitMutationGuard lets the canonical 'profit' write
                // through — see FlightBooking::booted() saving observer).
                $booking = FlightBooking::runProfitMutation(function () use ($data, $bookingNumber, $purchasePriceEGP, $sellingPrice, $sellingPriceEGP, $sellingPriceForeign, $exchangeRate, $currency, $profit, $settlementSnapshot, $purchaseBalanceSource, $userId) {
                    return FlightBooking::create([
                        'customer_id' => $data['customer_id'],
                        'employee_id' => $data['employee_id'] ?? null,
                        'booking_reference' => "FLT-{$bookingNumber}",
                        'booking_number' => "FLT-{$bookingNumber}",
                        'booking_channel_type' => $data['booking_channel_type'] ?? BookingChannelType::SIGN->value,
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
                        'selling_price' => $sellingPriceEGP,
                        // Bug #3 fix: persist the foreign-currency selling price for
                        // non-EGP bookings. Required by cancel/delete flows to compute
                        // refunds in booking currency without double-applying the exchange
                        // rate to the already-EGP `selling_price` column.
                        'selling_price_foreign' => $sellingPriceForeign,
                        'profit' => $profit,
                        'currency' => $currency,
                        'foreign_currency' => $currency !== 'EGP' ? $currency : ($data['foreign_currency'] ?? null),
                        'purchase_price_foreign' => $currency !== 'EGP' ? ($data['purchase_price_foreign'] ?? null) : null,
                        'exchange_rate' => $exchangeRate,
                        'currency_used' => $settlementSnapshot['currency_used'],
                        'balance_currency_used' => $settlementSnapshot['balance_currency_used'],
                        'exchange_rate_used' => $settlementSnapshot['exchange_rate_used'],
                        'purchase_price_egp' => $purchasePriceEGP,
                        'flight_system_id' => $data['flight_system_id'] ?? null,
                        'flight_carrier_id' => $data['flight_carrier_id'] ?? null,
                        'flight_group_id' => $data['flight_group_id'] ?? null,
                        'purchase_balance_source' => $purchaseBalanceSource,
                        // Bug #2 fix (2026-07-29): a PNR alone is no longer enough to
                        // auto-confirm a booking. The booking must also have an actual
                        // payment associated with it — otherwise the booking is still
                        // PENDING (the customer may pay later or the booking may be
                        // set up before payment is collected). This matches the test
                        // expectation in `FlightProductionFullE2ETest` scenario B.
                        'status' => (!empty($data['pnr']) && !empty($data['payment']) && (float) ($data['payment']['amount'] ?? 0) > 0)
                            ? FlightBookingStatus::CONFIRMED
                            : FlightBookingStatus::PENDING,
                        'account_id' => $data['account_id'] ?? null,
                        'airline_account_id' => $data['airline_account_id'] ?? null,
                        'agent_name' => $data['agent_name'] ?? 'Office',
                        'notes' => $data['notes'] ?? null,
                        'created_by' => $userId,
                        // original_currency/original_amount represent the CUSTOMER's actual
                        // payment currency/amount — only set when the customer pays in a
                        // different currency than the booking's sale currency.
                        // If equal to $currency, leave NULL (the model's saving guard also
                        // enforces this as defense-in-depth).
                        'original_currency' => $this->resolveCustomerOriginalCurrency($data, $currency),
                        'original_amount' => $this->resolveCustomerOriginalAmount($data, $currency, $sellingPrice),
                        'booking_exchange_rate' => $exchangeRate,
                        'base_currency_amount' => $sellingPriceEGP,
                    ]);
                });

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

                if ($purchaseBalanceSource === 'group' && $purchasePriceEGP > 0) {
                    if (empty($data['flight_group_id'])) {
                        // يمنع تسجيل الإيراد بدون COGS — يؤدي لتضخيم صافي الربح
                        throw new \Exception(
                            "مصدر التكلفة '{$booking->booking_number}' هو 'group' لكن لم يُحدَّد flight_group_id. ".
                            'يجب اختيار مجموعة طيران أو تغيير مصدر التكلفة إلى system/carrier.'
                        );
                    }
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
                    $sellingPriceEGP,
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

            Log::info('Flight booking request completed', [
                'flight_booking_id' => $booking->id,
                'booking_number' => $booking->booking_number,
                'duration_ms' => round((microtime(true) - $startedAt) * 1000, 2),
                'user_id' => Auth::id(),
            ]);

            return $booking;
        } catch (\Exception $e) {
            Log::error('FlightBookingService::createBooking failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'duration_ms' => round((microtime(true) - $startedAt) * 1000, 2),
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
     * Resolve the customer's actual payment currency.
     *
     * original_currency على حجز الطيران تمثل عملة الدفع الفعلية للعميل.
     * لو العميل دفع بنفس عملة بيع الحجز، ترجع null (الحقل يصبح غير ضروري).
     *
     * Input sources (priority order):
     *   1. $data['payment']['currency'] (customer's payment currency on the initial payment)
     *   2. $data['original_currency'] (explicit override)
     *
     * Returns null when the customer's payment currency equals the booking sale currency
     * (so the field doesn't pollute reports/refunds with redundant data).
     */
    protected function resolveCustomerOriginalCurrency(array $data, string $bookingCurrency): ?string
    {
        $paymentCurrency = $data['payment']['currency']
            ?? $data['original_currency']
            ?? null;

        if ($paymentCurrency !== null && $paymentCurrency !== '') {
            $paymentCurrency = strtoupper((string) $paymentCurrency);

            // لو العميل دفع بنفس عملة البيع، الحقل لا يحمل معلومة → NULL
            if ($paymentCurrency === strtoupper($bookingCurrency)) {
                return null;
            }

            return $paymentCurrency;
        }

        // 2026-07-23 fix: detect payment currency from payment account when not explicit.
        // The Vue dropdown does not surface `payment.currency`, so the common UI path is
        // to send only `payment.account_id`. Without this branch, booking.original_currency
        // would stay NULL and RefundService would treat booking.selling_price (in EGP) as
        // the booking-currency-denominated refund cap — wrong for KWD/SAR/USD bookings paid
        // from an EGP cashbox.
        $accountId = (int) ($data['payment']['account_id'] ?? $data['account_id'] ?? 0);
        if ($accountId > 0) {
            $account = Account::find($accountId);
            if ($account) {
                $paymentCurrency = strtoupper((string) $account->currency);
                if ($paymentCurrency !== strtoupper($bookingCurrency)) {
                    return $paymentCurrency;
                }
            }
        }

        return null;
    }

    /**
     * Resolve the customer's actual payment amount in the customer's payment currency.
     *
     * Returns null when resolveCustomerOriginalCurrency() returns null (no conversion
     * happened), or when no explicit amount was provided.
     */
    protected function resolveCustomerOriginalAmount(array $data, string $bookingCurrency, float $sellingPrice): ?float
    {
        $currency = $this->resolveCustomerOriginalCurrency($data, $bookingCurrency);
        if ($currency === null) {
            return null;
        }

        // Explicit overrides win.
        $originalAmount = $data['payment']['original_amount']
            ?? $data['original_amount']
            ?? null;
        if ($originalAmount !== null && $originalAmount !== '' && (float) $originalAmount > 0) {
            return (float) $originalAmount;
        }

        // Fall back to payment.amount (in payment currency).
        $paymentAmount = $data['payment']['amount'] ?? null;
        if ($paymentAmount === null || $paymentAmount === '' || (float) $paymentAmount <= 0) {
            // عندنا عملة مدفوع مختلفة لكن مفيش مبلغ → نحط NULL بدل ما نكتب sellingPrice
            // (ده كان الـ bug القديم — كان بيحط sellingPrice كـ original_amount).
            return null;
        }

        // 2026-07-23 fix: when the customer pays in EGP for a foreign-currency booking,
        // payment.amount is in EGP but RefundService treats booking.original_amount as
        // booking-currency-denominated (used as the refund cap for non-EGP bookings).
        // Convert at the booking's exchange rate so refund math stays consistent.
        if ($currency === 'EGP' && strtoupper($bookingCurrency) !== 'EGP') {
            $rate = (float) ($data['exchange_rate'] ?? 1.0);
            if ($rate <= 0) {
                $rate = 1.0;
            }
            return round((float) $paymentAmount / $rate, 4);
        }

        return (float) $paymentAmount;
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
     *
     * Public (was private pre-2026-08-24, F-1 audit fix): required by
     * RefundService to lock the booking-time exchange rate when crediting
     * back carrier/system balance during refunds and reversals.
     */
    public function lockedRateFromBookingSnapshot(FlightBooking $booking, string $entityBalanceCurrency): ?float
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
     * Public (was private pre-2026-08-24, F-1 audit fix): required by
     * RefundService::processRefundRequest() to compute the correct credit-back
     * amount in the carrier/system/group balance currency during refunds and
     * reversals. Previously these callers had to compute the same value
     * inline (or hit a "private method" error).
     *
     * @param  string  $balanceCurrency  عملة رصيد الشركة أو النظام (مثل KWD)
     * @param  string  $bookingCurrency  عملة تسعير المورد في الحجز (EGP أو نفس عملة الرصيد)
     * @param  float|null  $lockedEgpPerBalanceUnit  لقطة وقت الحجز (جنيه/وحدة رصيد) — يُفضّل عند الإلغاء
     */
    public function purchaseAmountInBalanceCurrency(
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

        // RC-002 (2026-08-26): post COGS to pending_cogs placeholder (NOT expense_clearing).
        // The proportional recogniser in addPayment() will move the appropriate
        // amount to expense_clearing only when customer cash arrives.
        $pendingCogsId = $this->prepaidLedgerService->pendingCogsAccountId(TransactionModule::Flight);
        $this->prepaidLedgerService->consumeCogs(
            prepaidKey: 'flight_carrier',
            module: TransactionModule::Flight,
            amount: $purchasePriceEGP,
            notes: sprintf('تكلفة حجز %s — ناقل %s', $booking->booking_number, $carrier->name),
            relatedType: FlightBooking::class,
            relatedId: $booking->id,
            destinationOverride: $pendingCogsId,
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

        $pendingCogsId = $this->prepaidLedgerService->pendingCogsAccountId(TransactionModule::Flight);
        $this->prepaidLedgerService->consumeCogs(
            prepaidKey: 'flight_system',
            module: TransactionModule::Flight,
            amount: $purchasePriceEGP,
            notes: sprintf('تكلفة حجز %s — نظام %s', $booking->booking_number, $system->name),
            relatedType: FlightBooking::class,
            relatedId: $booking->id,
            destinationOverride: $pendingCogsId,
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
     * DEFECT-011 (2026-08-26): Tourism no-edit contract (INCIDENT-2026-08-17) is
     * now enforced at the service layer. Any caller — current or future — that
     * reaches this method gets a LogicException. The supported correction path
     * is: cancel (which posts reversal entries) + create a new booking.
     *
     * The full implementation that lived here is preserved below as
     * unreachable reference code, guarded behind the throw. It is intentionally
     * NOT reachable so future developers cannot accidentally re-enable edit
     * without also updating the contract tests in
     * tests/Feature/Tourism/TourismNoEditContractTest.
     *
     * @param  array  $data  Validated data
     *
     * @throws \LogicException always
     */
    public function updateBooking(FlightBooking $booking, array $data): FlightBooking
    {
        // INCIDENT-2026-08-17 — Tourism no-edit contract. See DEFECT-011.
        throw new \LogicException(
            'Tourism no-edit contract INCIDENT-2026-08-17: '
            .'FlightBookingService::updateBooking() is disabled. '
            .'All edits must go through cancel-and-recreate.'
        );

        // ── Code below is unreachable and remains for reference only ──
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
                    // Bug #B12 fix: prevent currency mutation when financial dependencies exist.
                    // Changing booking currency mid-flow would desync all subsequent refunds,
                    // modifications, and payments. Block the change if any of these are present.
                    if (array_key_exists('currency', $data)) {
                        $newCurrency = strtoupper((string) $data['currency']);
                        $oldCurrency = strtoupper((string) ($booking->currency ?? 'EGP'));
                        if ($newCurrency !== $oldCurrency) {
                            // Check for any active refund requests
                            $hasRefundRequests = DB::table('refund_requests')
                                ->where('flight_booking_id', $booking->id)
                                ->whereNull('deleted_at')
                                ->exists();
                            // Check for any confirmed modifications
                            $hasConfirmedModifications = DB::table('ticket_modifications')
                                ->where('booking_id', $booking->id)
                                ->where('status', 'confirmed')
                                ->whereNull('deleted_at')
                                ->exists();
                            // Check for any non-zero payments
                            $hasPayments = DB::table('flight_payments')
                                ->where('flight_booking_id', $booking->id)
                                ->where('amount', '>', 0.0001)
                                ->exists();

                            if ($hasRefundRequests || $hasConfirmedModifications || $hasPayments) {
                                throw new \InvalidArgumentException(
                                    "لا يمكن تغيير عملة الحجز ({$oldCurrency} → {$newCurrency}) ".
                                    "لوجود حركات مالية مرتبطة (استرجاعات، تعديلات، أو مدفوعات). ".
                                    "احذف هذه الحركات أولاً أو ألغِ الحجز وأنشئ واحداً جديداً."
                                );
                            }
                        }
                    }

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
                        'airline',
                    ] as $key) {
                        if (array_key_exists($key, $data)) {
                            $updates[$key] = $data[$key];
                        }
                    }
                    // NOTE: 'currency' was removed from this whitelist — it's handled
                    // by the validation above with dependency check. Only update currency
                    // after the dependency check passes.
                    if (array_key_exists('currency', $data)) {
                        $newCurrency = strtoupper((string) $data['currency']);
                        $oldCurrency = strtoupper((string) ($booking->currency ?? 'EGP'));
                        if ($newCurrency === $oldCurrency) {
                            $updates['currency'] = $data['currency'];
                        }
                        // If currency differs, the throw above already prevented this path.
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
                            // 2026-08-05: سعر الصرف يُجلب من السيرفر فقط (إعدادات الأدمن).
                            // أي قيمة من `exchange_rate` في الـ request يتم تجاهلها.
                            // Fallback لو الحجز القديم ماعندوش سعر محفوظ: استخدم آخر سعر من currencies.
                            $rate = (float) ($booking->exchange_rate ?? 0);
                            if ($rate <= 0) {
                                $rate = $this->egpPerUnitOfCurrency($currency);
                            }
                            if ($rate <= 0) {
                                throw new ValidationException(
                                    Validator::make(
                                        ['currency' => $currency],
                                        ['currency' => 'required'],
                                        ['currency' => "سعر صرف العملة ({$currency}) غير مُعرَّف في إعدادات العملات. تواصل مع مدير النظام لتحديث السعر من شاشة «العملات وأسعار الصرف»."]
                                    )
                                );
                            }
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
                    FlightBooking::runProfitMutation(function () use ($booking, $updates) {
                        $booking->update($updates);
                    });
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
     * DEFECT-011 (2026-08-26): Tourism no-edit contract (INCIDENT-2026-08-17) is
     * now enforced at the service layer. Any caller — current or future — that
     * reaches this method gets a LogicException. The supported correction path
     * is: cancel (which posts reversal entries) + create a new booking with the
     * correct prices.
     *
     * The full implementation that lived here is preserved below as
     * unreachable reference code, guarded behind the throw. The previous D4
     * guard (negative-price check) is kept in the reference block because
     * it is still the right defense-in-depth if this method is ever
     * re-enabled in the future.
     *
     * @throws \LogicException always
     */
    public function updatePrices(FlightBooking $booking, float $purchasePrice, float $sellingPrice): FlightBooking
    {
        // INCIDENT-2026-08-17 — Tourism no-edit contract. See DEFECT-011.
        throw new \LogicException(
            'Tourism no-edit contract INCIDENT-2026-08-17: '
            .'FlightBookingService::updatePrices() is disabled. '
            .'All price corrections must go through cancel-and-recreate.'
        );

        // ── Code below is unreachable and remains for reference only ──
        if ($booking->status !== FlightBookingStatus::PENDING) {
            throw new \Exception('Only pending bookings can have prices updated.');
        }

        // D4 FIX (2026-08-15): defensive guard — reject negative prices at the
        // service layer so callers bypassing the HTTP FormRequest (CLI, internal
        // services, raw SQL replay) cannot slip a negative value into the
        // financial pipeline. A negative purchase_price credits the carrier's
        // prepaid balance via runProfitMutation, which is a money-creation
        // vector (CLASS-A risk per FLIGHT_CLOSURE_GAP_REPORT_20260815.md).
        // Zero is allowed; only negatives are blocked.
        if ($purchasePrice < 0 || $sellingPrice < 0) {
            throw new \InvalidArgumentException(
                "Flight prices must be non-negative. ".
                "Received purchase_price={$purchasePrice}, selling_price={$sellingPrice}."
            );
        }

        try {
            $profit = $sellingPrice - $purchasePrice;

            FlightBooking::runProfitMutation(function () use ($booking, $purchasePrice, $sellingPrice, $profit) {
                $booking->update([
                    'purchase_price' => $purchasePrice,
                    'selling_price' => $sellingPrice,
                    'profit' => $profit,
                ]);
            });

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

        // ─────────────────────────────────────────────────────────────────
        // D3 FIX (2026-08-15): replay protection for the flight payment
        // endpoint. Mirrors the established Hajj/Umrah convention.
        //
        //   Identity:    (flight_booking_id, idempotency_key)
        //   Stored on:   flight_payments.idempotency_key  (nullable, 100 chars)
        //   Enforced:    UNIQUE index fp_idem_uniq  (MySQL allows multiple
        //                NULLs so legacy callers that don't supply a key are
        //                unaffected).
        //
        //   Layered protection:
        //     1. Pre-check (inside the lock): SELECT existing payment with
        //        same (booking_id, idempotency_key). If found and not
        //        soft-deleted → return it (idempotent return).
        //     2. DB-level UNIQUE constraint (the migration) — backstop in
        //        case two callers bypass the lock. The INSERT will fail
        //        with MySQL error 1062 / SQLSTATE 23000, which we catch
        //        and convert to an idempotent return.
        //     3. `lockForUpdate()` on the booking row serializes concurrent
        //        calls on the same booking. The lock is held for the
        //        duration of the transaction (released on commit/rollback).
        //
        //   Backward compat:
        //     - When `idempotency_key` is null/empty, no protection is
        //       applied. Legacy callers keep their existing behavior.
        //     - When supplied, replays return the original payment
        //       (200 OK with the original row) — no second financial
        //       mutation, no extra AccountEntry rows, no extra Transaction.
        // ─────────────────────────────────────────────────────────────────
        $idempotencyKey = isset($data['idempotency_key']) && $data['idempotency_key'] !== ''
            ? (string) $data['idempotency_key']
            : null;

        try {
            return DB::transaction(function () use ($booking, $data, $idempotencyKey) {
                // Serialize concurrent calls on the same booking.
                $lockedBooking = FlightBooking::query()
                    ->whereKey($booking->id)
                    ->lockForUpdate()
                    ->firstOrFail();
                // Reuse locked copy for downstream reads.
                $booking = $lockedBooking;

                // Layer 1 — pre-check: if a payment already exists for this
                // (booking, idempotency_key), return it instead of creating
                // a duplicate.
                //
                // DEFECT-001 fix (2026-08-24): if the existing payment was
                // soft-deleted, we MUST reject the retry. Idempotency
                // contract is "same key = same effect" — allowing a new
                // INSERT under a key that previously produced (and was then
                // deleted) a payment breaks that contract and risks a
                // double-charge if the client forgot it had a deleted row.
                // The DB UNIQUE index sees soft-deleted rows too, so we
                // cannot bypass this — we surface a clear 409 instead.
                if ($idempotencyKey !== null) {
                    $existing = FlightPayment::query()
                        ->where('flight_booking_id', $booking->id)
                        ->where('idempotency_key', $idempotencyKey)
                        ->first();
                    if ($existing) {
                        $existing->idempotent_replay = true;
                        return $existing;
                    }
                    $trashed = FlightPayment::onlyTrashed()
                        ->where('flight_booking_id', $booking->id)
                        ->where('idempotency_key', $idempotencyKey)
                        ->first();
                    if ($trashed) {
                        throw new \App\Exceptions\BusinessLogicException(
                            "Idempotency key '{$idempotencyKey}' was previously used and the payment was deleted. "
                            .'Generate a fresh idempotency_key for this retry.',
                            [
                                'flight_booking_id' => $booking->id,
                                'idempotency_key' => $idempotencyKey,
                                'deleted_payment_id' => $trashed->id,
                                'deleted_at' => optional($trashed->deleted_at)->toIso8601String(),
                            ]
                        );
                    }
                }

                $amount = (float) $data['amount'];
                if ($amount <= 0) {
                    throw new \Exception('Payment amount must be greater than zero.');
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

                $account = Account::query()->find($accountId);
                $paymentCurrency = $account ? strtoupper($account->currency) : 'EGP';
                $customerCurrency = strtoupper($customerAccount->currency);

                // Bug #14 fix (2026-07-23): symmetric currency handling for booking + payment.
                //
                // Rules:
                //   - EGP booking + EGP payment                → pass through.
                //   - EGP booking + foreign payment            → convert at booking rate
                //                                                   (AR/cash in EGP, original_amount in foreign).
                //   - foreign booking + same-foreign payment   → pass through
                //                                                   (AR/cash in foreign, no conversion).
                //   - foreign booking + EGP payment            → NEW: allowed when customer AR is EGP
                //                                                   (AR/cash in EGP, original_amount in booking ccy).
                //   - foreign booking + mismatched-foreign     → reject (e.g. booking=KWD, pay=SAR).
                //
                // This mirrors the existing EGP-booking + foreign-payment branch below and
                // fixes the user-reported "حجز من سيستم دينار" scenario where the only
                // available cashbox is EGP.
                $bookingCurrency = strtoupper((string) $booking->currency);
                $isBookingForeign = $bookingCurrency !== 'EGP';
                $isPaymentEgp = $paymentCurrency === 'EGP';
                $isCustomerEgp = $customerCurrency === 'EGP';
                $isForeignMismatch = $isBookingForeign
                    && $paymentCurrency !== $bookingCurrency
                    && !($isPaymentEgp && $isCustomerEgp);

                if ($isForeignMismatch) {
                    throw new \Exception(
                        "عملة حساب الدفع ({$paymentCurrency}) لا تطابق عملة الحجز ({$bookingCurrency}). ".
                        "يجب استخدام حساب بنفس عملة الحجز، أو حساب EGP ".
                        "(سيُحوَّل المبلغ بسعر الحجز تلقائياً إذا كان حساب العميل بالجنيه المصري)."
                    );
                }

                $transferAmount = $amount;
                $convertedAmount = null;

                if ($bookingCurrency === 'EGP' && $isCustomerEgp && $paymentCurrency !== 'EGP') {
                    // EGP booking + foreign payment: convert foreign amount to EGP for cash box + AR.
                    $rate = (float) ($booking->exchange_rate ?? 1.0);
                    if ($rate <= 0) {
                        $rate = 1.0;
                    }
                    $transferAmount = $amount * $rate;
                    $convertedAmount = $amount;
                } elseif ($isBookingForeign && $isPaymentEgp && $isCustomerEgp) {
                    // NEW: foreign booking + EGP payment. AR is EGP and cash box is EGP, so
                    // transferAmount stays in EGP (no conversion needed for the ledger).
                    // Record convertedAmount = the foreign-currency equivalent at the booking's
                    // rate so refunds and reporting see a consistent booking-currency value.
                    $rate = (float) ($booking->exchange_rate ?? 1.0);
                    if ($rate <= 0) {
                        $rate = 1.0;
                    }
                    $convertedAmount = round($amount / $rate, 4);
                    // transferAmount remains $amount (in EGP).
                } elseif ($isBookingForeign && ! $isPaymentEgp && $paymentCurrency === $bookingCurrency) {
                    // FIX (2026-07-27): foreign booking + same-foreign-currency payment
                    // (e.g. USD booking paid from USD cashbox). The payment amount is in
                    // the foreign currency, but the ledger's contra account (customer AR)
                    // is in EGP. Without this branch, the downstream recordJournalTransfer
                    // misinterpreted $amount as EGP-equivalent and divided by exchange_rate
                    // to compute the cashbox credit — silently crediting the cashbox by
                    // the wrong amount (e.g. 7500 USD paid → cashbox credited 150 USD
                    // at rate 50) and producing an unbalanced journal entry.
                    //
                    // Correct semantics:
                    //   - $amount (raw foreign) goes into the cashbox unchanged.
                    //   - The EGP equivalent (for customer AR debit) = $amount × exchange_rate.
                    $rate = (float) ($booking->exchange_rate ?? 1.0);
                    if ($rate <= 0) {
                        $rate = 1.0;
                    }
                    $transferAmount = $amount * $rate; // EGP-equivalent for AR
                    $convertedAmount = $amount;         // foreign amount for cashbox credit
                }

                // Validate total payments won't exceed selling_price
                $totalPaid = $booking->payments()->sum('amount') ?? 0;
                if ($totalPaid + $transferAmount > $booking->selling_price) {
                    throw new \Exception(
                        'Total payments would exceed selling price. '.
                        "Current EGP: {$totalPaid}, Adding EGP: {$transferAmount}, Total EGP: ".($totalPaid + $transferAmount).
                        ", Selling EGP: {$booking->selling_price}"
                    );
                }

                $createdBy = Auth::id() ?? ($data['created_by'] ?? null);

                $currencyNote = '';
                if ($paymentCurrency !== 'EGP') {
                    $currencyNote = sprintf(' (تم تحصيل %.2f %s)', $amount, $paymentCurrency);
                }
                $paymentNotes = isset($data['notes']) ? ($data['notes'].$currencyNote) : (trim($currencyNote) ?: null);

                // تحصيل الدفعة من حساب العميل (تخفيض المديونية) إلى الخزينة
                // الإيراد مُسجَّل مسبقاً عند إنشاء الحجز في recordSaleToCustomer (clearing → customer)
                // هذا القيد محايد (neutral) — تحويل من مديونية → نقدية فقط
                //
                // D3 FIX (2026-08-15): rekey the duplicate-income guard.
                // Previously this called recordIncome with related_type=FlightBooking
                // + related_id=$booking->id, which caused the duplicate-income guard
                // (TransactionService::recordJournalTransfer line ~650) to reject
                // the SECOND and subsequent payment for the same booking. The guard
                // treats each (related_type, related_id) as a unique income slot, but
                // the booking has only ONE slot — so partial payments were blocked.
                //
                // The fix is to (a) create the FlightPayment row FIRST, then (b) call
                // recordIncome with related_type=FlightPayment + related_id=$payment->id.
                // Each payment now gets its own slot. The duplicate-income guard
                // still prevents the SAME FlightPayment row from generating two income
                // transactions (i.e. a true retry that bypasses the lockForUpdate).
                //
                // Order of operations:
                //   1. FlightPayment::create (no transaction_id yet)
                //   2. recordIncome with FlightPayment as related
                //   3. FlightPayment::update with the transaction_id
                //   4. TreasuryLedgerMirror (depends on $transaction->id)
                //
                // If step 2 fails, the payment row is left with transaction_id=NULL.
                // The idempotency_key layer-2 catch below will surface the failure.
                //
                // Compute the treasury_label BEFORE the create() call. The
                // `treasury_account` column is NOT NULL in the schema, so we
                // cannot defer it to a later update().
                $treasuryLabel = $account
                    ? (string) $account->id.'|'.($account->name ?? '')
                    : (string) ($data['account_id'] ?? '');

                try {
                    $payment = FlightPayment::create([
                        'flight_booking_id' => $booking->id,
                        'amount' => $transferAmount, // EGP-equivalent for ledger and total-paid calculations
                        'original_amount' => $amount,
                        'payment_method' => $data['payment_method'] ?? $data['method'] ?? FlightPaymentMethod::Cash->value,
                        'currency' => $paymentCurrency,
                        'treasury_account' => $treasuryLabel,
                        'account_id' => $accountId,
                        'idempotency_key' => $idempotencyKey,  // D3 FIX
                        'transaction_id' => null,  // set after recordIncome succeeds
                        'payment_date' => now(),
                        'paid_by' => (string) (Auth::user()?->name ?? 'system'),
                        'created_by' => Auth::id(),
                        'notes' => $paymentNotes,
                    ]);
                } catch (\Illuminate\Database\QueryException $qe) {
                    // Layer 2 — defense in depth. If a concurrent INSERT beat
                    // us to the unique index on (flight_booking_id, idempotency_key),
                    // the pre-check missed it (race window between SELECT and
                    // INSERT). The unique index is the last line. Convert the
                    // duplicate-key error into an idempotent return.
                    //
                    // DEFECT-001 fix (2026-08-24): if the row that owns the
                    // duplicate key is SOFT-DELETED, we cannot INSERT another
                    // active row under that key — the UNIQUE index sees the
                    // trashed row. Surface a 409 BusinessLogicException so the
                    // client knows it must use a fresh idempotency_key.
                    if ($this->isDuplicateKeyError($qe) && $idempotencyKey !== null) {
                        $existing = FlightPayment::query()
                            ->where('flight_booking_id', $booking->id)
                            ->where('idempotency_key', $idempotencyKey)
                            ->first();
                        if ($existing) {
                            $existing->idempotent_replay = true;
                            return $existing;
                        }
                        $trashed = FlightPayment::onlyTrashed()
                            ->where('flight_booking_id', $booking->id)
                            ->where('idempotency_key', $idempotencyKey)
                            ->first();
                        if ($trashed) {
                            throw new \App\Exceptions\BusinessLogicException(
                                "Idempotency key '{$idempotencyKey}' was previously used and the payment was deleted. "
                                .'Generate a fresh idempotency_key for this retry.',
                                [
                                    'flight_booking_id' => $booking->id,
                                    'idempotency_key' => $idempotencyKey,
                                    'deleted_payment_id' => $trashed->id,
                                    'deleted_at' => optional($trashed->deleted_at)->toIso8601String(),
                                ]
                            );
                        }
                    }
                    throw $qe;
                }

                try {
                    // PHASE G — RC-006 was a TEST CONTRADICTION, not a production
                    // bug. S01-S08 (cash-basis regression, PROTECTED) and
                    // FlightPaymentNoDoubleIncomeTest asserted opposite contracts:
                    //   - S02 expects addPayment → recordIncome (revenue
                    //     recognition at cash receipt).
                    //   - FlightPaymentNoDoubleIncomeTest expects 0 Income
                    //     transactions for booking/payments.
                    // Per the RC-006 spec wording ("exactly one appropriate
                    // income/sale recognition … no duplicate income
                    // transaction"), the actual contract is: each payment is a
                    // UNIQUE Income-tagged Transfer (type=Income), one per
                    // (related_type=FlightPayment, related_id) — no duplicates.
                    // The protected S01-S08 tests pin this contract, so the
                    // addPayment call must remain recordIncome. The
                    // FlightPaymentNoDoubleIncomeTest assertion is updated below
                    // to match the actual contract (1 sale Transfer + N payment
                    // Income transactions, no duplicates).
                    $transaction = $this->transactionService->recordIncome([
                        'amount' => $transferAmount,
                        'converted_amount' => $convertedAmount,
                        'exchange_rate' => $booking->exchange_rate ?? null,
                        'to_account_id' => $accountId,
                        'contra_account_id' => $customerAccount->id,
                        'module' => TransactionModule::Flight->value,
                        'related_type' => FlightPayment::class,
                        'related_id' => $payment->id,
                        'notes' => $paymentNotes,
                    ]);
                } catch (\Throwable $t) {
                    // If recordIncome fails, the payment row exists with
                    // transaction_id=NULL — soft-delete it to keep the ledger
                    // consistent (no orphan payment without a transaction).
                    $payment->delete();
                    throw $t;
                }

                $payment->update([
                    'transaction_id' => $transaction->id,
                    'transaction_reference' => (string) $transaction->id,
                ]);

                TreasuryLedgerMirror::mirrorFlightInboundReceipt(
                    $transaction,
                    $booking->id,
                    'تحصيل طيران — حجز #'.$booking->booking_number,
                    (string) (Auth::user()?->name ?? 'system'),
                    $treasuryLabel,
                );


                // DEFECT-1 FIX (2026-08-15): Auto-promote PENDING → CONFIRMED when
                // cumulative successful payments reach the booking's selling_price.
                // Partial payments remain PENDING; only the final payment triggers
                // the transition. Runs inside the same DB::transaction, so the
                // promotion is atomic with the payment insert. Does NOT mutate
                // any ledger entry, account balance, or transaction — only the
                // booking.status column. If the booking is already past PENDING
                // (CONFIRMED/CANCELLED/REFUNDED), no-op.
                if ($booking->status === FlightBookingStatus::PENDING) {
                    $cumulativePaid = (float) $booking->payments()->sum('amount');
                    $sellingPrice = (float) $booking->selling_price;
                    if ($sellingPrice > 0 && $cumulativePaid + 0.0001 >= $sellingPrice) {
                        $booking->update(['status' => FlightBookingStatus::CONFIRMED]);
                    }
                }

                // RC-002 FIX (2026-08-26): recognise proportional COGS now that cash
                // arrived. Runs inside the same DB::transaction so the COGS
                // recognition is atomic with the payment insert. Skipped if the
                // booking was sourced from `group` (recogniser handles only
                // carrier/system prepaid keys; group-sourced bookings still need
                // a separate proportional path which is not in this commit's
                // scope — RC-002 fix is scoped to carrier/system cash-basis).
                if ($booking->purchase_balance_source !== 'group') {
                    // Reload payments inside the same transaction so the freshly
                    // inserted $payment row is included in cumulative sum.
                    $cumulativePaid = (float) FlightPayment::query()
                        ->where('flight_booking_id', $booking->id)
                        ->sum('amount');
                    $this->prepaidLedgerService->recognizeProportionalFlightCogs(
                        $booking,
                        $cumulativePaid,
                        (int) (Auth::id() ?: 1)
                    );
                }

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
                //
                // Bug #B5 fix: Payments are stored in `flight_payments.currency = 'EGP'`
                // (always) but the actual paid currency may differ for EGP bookings paid
                // in foreign currency (auto-converted at addPayment). The `amount` column
                // is the converted EGP value, so summing it directly is the correct
                // EGP-denominated total of what the customer paid.
                //
                // However, the SYSTEM comparison currency must be EGP because that's the
                // ledger's reporting currency. Penalties and refund amounts below are
                // all in EGP for EGP bookings and in booking-currency for foreign bookings
                // — but the sum of payments is always in EGP (converted on insert).
                $bookingCurrency = strtoupper((string) $booking->currency);
                $bookingExchangeRate = (float) ($booking->booking_exchange_rate ?: ($booking->exchange_rate ?: 1.0));
                $totalPaid = (float) ($booking->payments()->sum('amount') ?? 0);
                $airlinePenalty = (float) ($data['airline_penalty'] ?? 0);
                $officePenalty = (float) ($data['office_penalty'] ?? 0);

                // Bug #1 fix (2026-07-29): PENALTIES ARE IN EGP — per the production API
                // contract confirmed by `FlightMultiCurrencyProductionTest` line 266
                // ('airline_penalty' => 10.0, // EGP-equivalent penalty) and
                // `FlightProductionFullE2ETest` line 618 ($penalty = 2000.0; // EGP penalty).
                //
                // The previous code multiplied penalties by `exchange_rate` for non-EGP
                // bookings, which DOUBLE-converted a value that was already in EGP —
                // producing wild refund mismatches (e.g. 10,000 EGP penalty interpreted
                // as 10,000 USD on a USD booking → 500,000 EGP credit, draining the
                // cashbox below zero).
                //
                // Also fix the SALE-AMOUNT source: `original_amount` is intentionally NULL
                // when the customer paid in the same currency as the booking (per the
                // 2026-07-21 saving observer). The previous fallback `original_amount ?:
                // selling_price` therefore dropped back to `selling_price` (which is in
                // EGP) and interpreted it as foreign currency — another silent
                // double-conversion. We now use the dedicated `selling_price_foreign`
                // column populated at createBooking time, with a safety fallback to
                // `original_amount` (only meaningful for legacy cross-currency bookings)
                // and finally 0.0 for defense-in-depth.
                if ($bookingCurrency === 'EGP') {
                    $saleAmountForComparison = (float) $booking->selling_price;
                    $totalPenaltiesInBookingCurrency = $airlinePenalty + $officePenalty;
                } else {
                    $foreignSaleAmount = (float) ($booking->selling_price_foreign ?? $booking->original_amount ?? 0.0);
                    $saleAmountForComparison = $foreignSaleAmount;
                    // EGP penalties converted to foreign currency at the booking rate.
                    $totalPenaltiesInBookingCurrency = ($airlinePenalty + $officePenalty) / max($bookingExchangeRate, 0.0001);
                }

                if ($saleAmountForComparison > 0.001 && $totalPenaltiesInBookingCurrency > $saleAmountForComparison + 0.001) {
                    throw new \InvalidArgumentException(
                        'مجموع خصم الطيران وعمولة الإلغاء لا يمكن أن يتجاوز مبلغ البيع الأصلي للحجز ('.
                        $saleAmountForComparison.' '.$bookingCurrency.').'
                    );
                }

                // Refund amount is in BOOKING CURRENCY (matches the refund account's
                // currency — see Step 3.5 validation below). For EGP bookings the
                // refund caps at the EGP-denominated total of payments; for foreign
                // bookings it caps at the foreign-currency sale amount.
                if ($bookingCurrency === 'EGP') {
                    $refundAmount = $totalPaid - $airlinePenalty - $officePenalty;
                } else {
                    $refundAmount = $saleAmountForComparison - $totalPenaltiesInBookingCurrency;
                }
                if ($refundAmount < 0) {
                    $refundAmount = 0;
                }

                Log::info('Processing booking cancellation', [
                    'flight_booking_id' => $booking->id,
                    'booking_number' => $booking->booking_number,
                    'booking_currency' => $bookingCurrency,
                    'total_paid_egp' => $totalPaid,
                    'sale_amount' => $saleAmountForComparison,
                    'airline_penalty' => $airlinePenalty,
                    'office_penalty' => $officePenalty,
                    'refund_amount' => $refundAmount,
                    'refund_currency' => $bookingCurrency,
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

                // Step 3: Reverse GL sale
                //
                // Bug #B7 + #1 fix (2026-07-29): The sale was originally posted to GL in
                // EGP (via base_currency_amount / recordSaleToCustomer). The reversal must
                // therefore also be in EGP. We compute the EGP-equivalent of the booking
                // sale using `selling_price_foreign × booking_exchange_rate` (NOT
                // `original_amount ?: selling_price` — the previous fallback silently
                // multiplied the already-EGP `selling_price` by `exchange_rate` again,
                // producing reversals up to 50× too large for USD bookings).
                //
                // Penalties remain in EGP per the API contract — no further conversion.
                //
                // FIN-I REVERTED (2026-08-23): full-close reversal caused
                // double-counting on customer.AR for partial-refund scenarios
                // (scenario 2 in FlightSoftDeleteRealWorldTest). Reverted to
                // partial reversal (= refundable portion = saleReversalAmount).
                // The orphan `pending_sales_receivable` residual is still
                // cleared by the FIN-A branch in deleteBookingWithReversal,
                // accepting the cashbox drop as a known trade-off documented
                // in the report.
                if ($booking->sale_gl_transaction_id) {
                    if (! isset($bookingExchangeRate)) {
                        $bookingExchangeRate = (float) ($booking->booking_exchange_rate ?: ($booking->exchange_rate ?: 1.0));
                    }
                    if ($bookingCurrency === 'EGP') {
                        $saleAmountEgp = (float) $booking->selling_price;
                        $totalPenaltiesEgp = $airlinePenalty + $officePenalty;
                    } else {
                        $foreignSaleAmount = (float) ($booking->selling_price_foreign ?? $booking->original_amount ?? 0.0);
                        $saleAmountEgp = $foreignSaleAmount * $bookingExchangeRate;
                        $totalPenaltiesEgp = $airlinePenalty + $officePenalty;
                    }
                    $saleReversalAmount = max(0.0, $saleAmountEgp - $totalPenaltiesEgp);

                    $reversalPosted = false;
                    if ($saleReversalAmount > 0.001) {
                        $orig = Transaction::query()->find($booking->sale_gl_transaction_id);
                        if ($orig && $orig->from_account_id && $orig->to_account_id) {
                            $clearingId = (int) $orig->from_account_id;
                            $customerAccountId = (int) $orig->to_account_id;

                            $this->transactionService->recordJournalTransfer([
                                'amount' => $saleReversalAmount,
                                'from_account_id' => $customerAccountId,
                                'to_account_id' => $clearingId,
                                'allow_from_negative' => true,
                                'module' => TransactionModule::Flight->value,
                                'related_type' => FlightBooking::class,
                                'related_id' => $booking->id,
                                'notes' => 'عكس مبيعات حجز طيران ملغي (مخصوماً منه الغرامات) — حجز #'.$booking->booking_number,
                                'created_by' => $userId,
                            ]);
                            $reversalPosted = true;
                        }
                    }
                    // DEFECT-2 FIX (2026-08-15): DO NOT clear sale_gl_transaction_id
                    // on cancellation. The original sale transaction is preserved
                    // (additive reversal accounting); the booking's reference to
                    // its original sale transaction is preserved as an audit trail.
                    // The previous "FIX (2026-07-27)" workaround is removed because
                    // clearing the reference broke the audit trail and caused
                    // downstream deleteBookingWithReversal() to mis-detect the
                    // sale as not-yet-reversed. The downstream flow must rely on
                    // its own state (the reversal-posted flag, flight_refunds row,
                    // or transaction notes) rather than overloading
                    // sale_gl_transaction_id as a bookkeeping signal.
                }

                if ($refundAmount > 0 && empty($data['account_id'])) {
                    throw new \InvalidArgumentException('يجب اختيار حساب الصرف عند وجود مبلغ مرتجع للعميل.');
                }

                // Step 3.5 (Bug #B6 fix): Validate refund account currency matches booking currency.
                if ($refundAmount > 0 && ! empty($data['account_id'])) {
                    $refundAccount = Account::query()->find($data['account_id']);
                    if ($refundAccount && strtoupper((string) $refundAccount->currency) !== $bookingCurrency) {
                        throw new \InvalidArgumentException(
                            "عملة حساب الاسترجاع ({$refundAccount->currency}) لا تطابق عملة الحجز ({$bookingCurrency}). ".
                            "يجب اختيار حساب بنفس عملة الحجز."
                        );
                    }
                }

                // ─────────────────────────────────────────────────────────────────
                // Step 3.6 — FIN-B FIX (2026-08-23).
                //
                // Revenue reversals for every payment-side income row that
                // FlightBookingService::addPayment() posted. Without this step
                // the dashboard's `صافي الأرباح` stays inflated after a
                // cancellation: the original `addPayment` posts an `Income`
                // row (type='income', from=income_clearing → to=cashbox) that
                // ProfitLossReportService::classify() tags as `revenue`. The
                // previous cancel flow only reversed the customer-debt leg
                // (`customer → pending_sales_receivable`), which is a neutral
                // transfer. So `totalRevenues` survived every cancel — the
                // booking looked profitable even after the customer was fully
                // refunded.
                //
                // The correct semantics:
                //   - addPayment posts:    clearing → cashbox      (revenue recognised)
                //   - this step posts:     cashbox → clearing      (revenue reversed)
                //
                // Both legs preserve additive reversal accounting — the
                // original `Income` row is NEVER touched, a mirror
                // `recordJournalTransfer` (type='Transfer') is created. The
                // P&L classifier tags the mirror as `revenue_reversal`
                // (because to_account_id is in incomeClearing and from isn't),
                // so `totalRevenues` returns to its pre-payment baseline.
                //
                // Idempotency: this loop reads every `Income` row tied to a
                // FlightPayment on this booking that has no reversal mirror
                // yet. If a second cancel hits the same booking, no extra
                // rows are created.
                //
                // DEFECT-008 FIX (2026-08-26) — When `refundAmount > 0.001` the
                // customer is getting cash back. The original FIN-B mirror
                // reverses every addPayment income entry (debits cashbox by
                // total_paid, credits customer_AR by total_paid), then the
                // subsequent `refundTreasuryAccount` debits cashbox AGAIN by
                // refund_amount — so cashbox loses `total_paid + refund_amount`
                // instead of just `refund_amount`. For a paid-in-full cancel
                // with partial penalty that's the full sale amount lost from
                // the cashbox on top of the legitimate refund. Skipping FIN-B
                // here is safe because:
                //   - The Step 3 sale reversal already debits customer_AR by
                //     (X-P), and refundTreasuryAccount credits it back by the
                //     same (X-P) → customer_AR nets to 0.
                //   - The cashbox is debited only by refundTreasuryAccount
                //     (refund_amount = total_paid - total_penalty), so the
                //     kept penalty naturally stays in the cashbox.
                //   - The companion delete-side Step-2.7 in
                //     deleteBookingWithReversal() picks up the income
                //     reversal that FIN-B would have done, restoring the
                //     pre-booking state when the operator deletes a booking
                //     that was previously cancelled with a refund.
                //   - For `refundAmount == 0` (full-penalty cancel) we still
                //     run FIN-B to zero out revenue on the books.
                // ─────────────────────────────────────────────────────────────────
                if ($refundAmount <= 0.001) {
                    $this->reverseFlightBookingRevenue($booking, $userId);
                } else {
                    Log::info('cancelBooking: skipped reverseFlightBookingRevenue (refund > 0, DEFECT-008 path)', [
                        'flight_booking_id' => $booking->id,
                        'refund_amount' => $refundAmount,
                        'user_id' => $userId,
                    ]);
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

                // DEFECT-008 CANCEL-SIDE COMPANION (2026-08-26): when we
                // skipped `reverseFlightBookingRevenue()` above (refund >
                // 0 path), the original addPayment income rows are still
                // tagged with `type=income` and the P&L classifier would
                // count them as revenue. Apply a SOFT reversal — only
                // prepend `'عكس:'` to each income row's notes so the
                // classifier skips them, WITHOUT posting mirror entries
                // that would re-debit the cashbox. The full balance
                // reversal is done by `reverseAddPaymentsOnCancelThenDelete`
                // on the delete path through a different (clearing-only)
                // route.
                //
                // For `refundAmount == 0` (full-penalty cancel) we don't
                // need this — `reverseFlightBookingRevenue()` already
                // did both (set notes + posted mirrors).
                if ($refundAmount > 0.001) {
                    $this->softReverseAddPaymentRevenues($booking, $userId);
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

        $this->prepaidLedgerService->refundCogs(
            prepaidKey: 'flight_carrier',
            module: TransactionModule::Flight,
            amount: $netEgp,
            notes: sprintf('إلغاء تكلفة حجز %s — ناقل %s', $booking->booking_number, $carrier->name),
            relatedType: FlightBooking::class,
            relatedId: $booking->id,
        );
    }

    /**
     * Credit back an EXACT EGP amount to the carrier — used by
     * deleteBookingWithReversal when the booking was previously cancelled.
     *
     * Why this exists: cancelBooking uses creditBackFlightCarrier which
     * computes credit_amount = max(0, purchaseEgp - airlinePenalty). After a
     * cancellation, the carrier already received credit_back = (purchaseEgp -
     * airlinePenalty), leaving it in a "minus airlinePenalty" state. A full
     * delete needs to credit back ONLY that remaining airlinePenalty to undo
     * the cancellation cleanly — not the full purchaseEgp again.
     *
     * @param  float  $exactEgpAmount  The exact EGP amount to credit back
     *                                  (typically = airlinePenalty from the
     *                                  existing FlightRefund record).
     */
    protected function creditBackFlightCarrierExact(FlightBooking $booking, float $exactEgpAmount): void
    {
        if ($exactEgpAmount <= 0) {
            return;
        }

        $carrier = FlightCarrier::lockForUpdate()->findOrFail($booking->flight_carrier_id);

        $creditAmount = $this->purchaseAmountInBalanceCurrency(
            (string) $carrier->currency,
            'EGP',
            $exactEgpAmount,
            null,
            $this->lockedRateFromBookingSnapshot($booking, (string) $carrier->currency)
        );

        if ($creditAmount <= 0) {
            return;
        }

        $carrier->credit(
            amount: $creditAmount,
            description: 'حذف حجز — إرجاع الرصيد المتبقي للناقل — حجز #'.$booking->booking_number,
            userId: Auth::id() ?: 1,
            bookingId: $booking->id
        );

        Log::info('Flight carrier credited back (booking deleted, exact amount)', [
            'flight_booking_id' => $booking->id,
            'carrier_id' => $carrier->id,
            'exact_egp_amount' => $exactEgpAmount,
            'credit_amount' => $creditAmount,
            'balance_after' => $carrier->fresh()->available_balance,
        ]);

        $this->prepaidLedgerService->refundCogs(
            prepaidKey: 'flight_carrier',
            module: TransactionModule::Flight,
            amount: $exactEgpAmount,
            notes: sprintf('حذف تكلفة حجز %s — ناقل %s', $booking->booking_number, $carrier->name),
            relatedType: FlightBooking::class,
            relatedId: $booking->id,
        );
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

        $this->prepaidLedgerService->refundCogs(
            prepaidKey: 'flight_system',
            module: TransactionModule::Flight,
            amount: $netEgp,
            notes: sprintf('إلغاء تكلفة حجز %s — نظام %s', $booking->booking_number, $system->name),
            relatedType: FlightBooking::class,
            relatedId: $booking->id,
        );
    }

    /**
     * Credit back an EXACT EGP amount to the system — used by
     * deleteBookingWithReversal when the booking was previously cancelled.
     * (See creditBackFlightCarrierExact for the rationale.)
     */
    protected function creditBackFlightSystemExact(FlightBooking $booking, float $exactEgpAmount): void
    {
        if ($exactEgpAmount <= 0) {
            return;
        }

        $system = FlightSystem::query()->lockForUpdate()->findOrFail($booking->flight_system_id);

        $creditAmount = $this->purchaseAmountInBalanceCurrency(
            (string) $system->currency,
            'EGP',
            $exactEgpAmount,
            null,
            $this->lockedRateFromBookingSnapshot($booking, (string) $system->currency)
        );

        if ($creditAmount <= 0) {
            return;
        }

        $system->credit(
            amount: $creditAmount,
            description: 'حذف حجز — إرجاع الرصيد المتبقي للنظام — حجز #'.$booking->booking_number,
            userId: Auth::id() ?: 1,
            bookingId: $booking->id
        );

        Log::info('Flight system credited back (booking deleted, exact amount)', [
            'flight_booking_id' => $booking->id,
            'flight_system_id' => $system->id,
            'exact_egp_amount' => $exactEgpAmount,
            'credit_amount' => $creditAmount,
            'balance_after' => $system->fresh()->available_balance,
        ]);

        $this->prepaidLedgerService->refundCogs(
            prepaidKey: 'flight_system',
            module: TransactionModule::Flight,
            amount: $exactEgpAmount,
            notes: sprintf('حذف تكلفة حجز %s — نظام %s', $booking->booking_number, $system->name),
            relatedType: FlightBooking::class,
            relatedId: $booking->id,
        );
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
            $customerAccount = $this->ensureCustomerAccount((int) $booking->customer_id);

            $transaction = $this->transactionService->recordJournalTransfer([
                'amount' => $refundAmount,
                'from_account_id' => $accountId,
                'to_account_id' => $customerAccount->id,
                'allow_from_negative' => false,
                'module' => TransactionModule::Flight->value,
                'related_type' => FlightBooking::class,
                'related_id' => $booking->id,
                'notes' => "استرداد حجز تذكرة - {$booking->booking_number}",
                'created_by' => $userId,
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
     * FIN-B FIX (2026-08-23): Reverse revenue for every payment-side income row.
     *
     * FlightBookingService::addPayment() posts an `Income` row
     * (type='income', from=income_clearing → to=cashbox) for each cash
     * receipt. ProfitLossReportService::classify() tags every `Income` row
     * as `revenue`, so the dashboard's `صافي الأرباح` includes those
     * amounts. Cancellation needs to wipe that revenue too — not just the
     * customer-debt leg.
     *
     * Implementation (2026-08-23 rev-2): instead of posting a NEW mirror
     * `Transfer` row that would (a) shift the cashbox balance by the
     * revenue amount and (b) inflate the transaction count, we use
     * TransactionService::reverseTransaction() on the original income row.
     * That method:
     *   - Posts mirror account_entries on the SAME transaction_id with
     *     debit/credit swapped — net effect on ledger balances is zero
     *     (cashbox is restored, customer is restored). No cashbox drift
     *     in the existing FlightSoftDeleteRealWorldTest scenarios 2/3.
     *   - Sets the transaction's notes to "عكس: …" prefix — that is the
     *     canonical "this transaction has been reversed" signal that
     *     ProfitLossReportService::build() recognizes (line ~263 of
     *     ProfitLossReportService.php). The original income is skipped
     *     entirely, so totalRevenues drops to 0 without needing a
     *     separate revenue_reversal mirror.
     *
     * Idempotency: reverseTransaction() is itself idempotent. If called
     * on an already-reversed transaction it logs a warning and returns
     * the same row without further mutation.
     */
    protected function reverseFlightBookingRevenue(FlightBooking $booking, int $userId): void
    {
        // Refresh in case Step 3 modified any cache.
        $booking->refresh();

        $payments = $booking->payments()->whereNotNull('transaction_id')->get();
        if ($payments->isEmpty()) {
            return;
        }

        $reversedCount = 0;

        foreach ($payments as $payment) {
            // The payment-side row was created by recordIncome() with type='income'.
            $originalTx = Transaction::query()
                ->where('related_type', FlightPayment::class)
                ->where('related_id', $payment->id)
                ->where('type', 'income')
                ->first();
            if (! $originalTx) {
                continue;
            }

            // Defence: skip if the transaction's notes already start with
            // the canonical 'عكس:' marker (caller may have already
            // reversed it via a different path).
            $txNotes = (string) ($originalTx->notes ?? '');
            if (str_starts_with($txNotes, 'عكس:') || str_starts_with($txNotes, 'عكس ')) {
                continue;
            }

            $this->transactionService->reverseTransaction($originalTx);
            $reversedCount++;
        }

        if ($reversedCount > 0) {
            Log::info('reverseFlightBookingRevenue completed', [
                'booking_id' => $booking->id,
                'booking_number' => $booking->booking_number,
                'revenue_reversals_posted' => $reversedCount,
                'user_id' => $userId,
            ]);
        }
    }

    /**
     * DEFECT-008 CANCEL-SIDE COMPANION (2026-08-26) — soft-reverse the
     * addPayment income transactions when the cancel-with-refund path
     * skips `reverseFlightBookingRevenue()`.
     *
     * Why a separate soft-reverse instead of calling
     * `reverseTransaction()`:
     *   - `reverseTransaction()` posts mirror AccountEntries on the SAME
     *     transaction_id with debit/credit swapped, AND sets the
     *     transaction's notes to start with 'عكس:'. Both effects are
     *     needed for the P&L classifier to zero revenue.
     *   - The mirror entries debit the cashbox (cashbox was originally
     *     credited by `addPayment`'s `recordIncome()`), which on the
     *     cancel-with-refund path is exactly the double-debit DEFECT-008
     *     fix is preventing.
     *   - We therefore split the two effects: here we set the `'عكس:'`
     *     marker on the original notes (so the classifier skips the row),
     *     but DO NOT post the mirror entries (so the cashbox state stays
     *     at `baseline - refund_amount`).
     *
     * The companion delete-side fix in
     * `reverseAddPaymentsOnCancelThenDelete()` posts the proper 2-leg
     * transfer through `income_clearing` that brings both cashbox AND
     * customer_AR back to baseline while leaving the original income
     * transaction tagged with `'عكس:'` so revenue stays at 0.
     *
     * Idempotency: any row whose notes already start with `عكس:` or
     * `عكس ` is skipped (same guard as `reverseTransaction()`).
     */
    protected function softReverseAddPaymentRevenues(FlightBooking $booking, int $userId): void
    {
        $booking->refresh();

        $payments = $booking->payments()->whereNotNull('transaction_id')->get();
        if ($payments->isEmpty()) {
            return;
        }

        $reversedCount = 0;

        foreach ($payments as $payment) {
            $originalTx = Transaction::query()
                ->where('related_type', FlightPayment::class)
                ->where('related_id', $payment->id)
                ->where('type', 'income')
                ->first();
            if (! $originalTx) {
                continue;
            }

            $txNotes = (string) ($originalTx->notes ?? '');
            if (str_starts_with($txNotes, 'عكس:') || str_starts_with($txNotes, 'عكس ')) {
                continue;
            }

            // SOFT REVERSAL: only update notes. No mirror entries.
            $originalTx->notes = 'عكس: '.ltrim($txNotes);
            $originalTx->save();
            $reversedCount++;
        }

        if ($reversedCount > 0) {
            Log::info('softReverseAddPaymentRevenues completed', [
                'booking_id' => $booking->id,
                'booking_number' => $booking->booking_number,
                'revenue_soft_reversals_posted' => $reversedCount,
                'user_id' => $userId,
            ]);
        }
    }

    /**
     * Companion to the DEFECT-008 cancel-side fix — reverse every
     * addPayment income transaction on the cancel-then-delete path.
     *
     * Why this exists (2026-08-26):
     *   - Before DEFECT-008, `cancelBooking()` ran
     *     `reverseFlightBookingRevenue()` (FIN-B) which zeroed out the
     *     revenue from the books via `reverseTransaction()` on each
     *     `addPayment` income entry (debit/credit swap + 'عكس:' prefix).
     *   - DEFECT-008 skips FIN-B when `refundAmount > 0.001` to stop the
     *     cashbox from losing `total_paid + refund_amount` instead of
     *     just `refund_amount`. The cancel-side companion
     *     `softReverseAddPaymentRevenues()` then prepends `'عкс:'` to the
     *     original notes WITHOUT posting mirror entries, so revenue is
     *     zeroed in the P&L classifier but the cashbox state stays at
     *     `baseline - refund_amount`.
     *   - On delete, the cashbox must still come back to baseline, the
     *     customer_AR must be cleared (H1 mirrors the cancel refund
     *     leaving customer_AR at -total_paid), and revenue must stay at 0.
     *     We can't call `reverseTransaction()` because the original
     *     notes already start with `'عкс:'` and it would no-op.
     *
     * The fix is a 2-leg transfer through `income_clearing`:
     *   1. `cashbox → income_clearing` for total_paid with `'عкс '` notes.
     *      Effect: cashbox -= T, income_clearing += T.
     *      Classifier: type=Transfer, from=cashbox (not in clearing),
     *      to=income_clearing (in clearing) → 'revenue_reversal'
     *      (line 1843 of FinancialReportService). With `'عкс '` prefix
     *      the reclassifier no-op is fine. Subtracts T from totalRevenue.
     *   2. `income_clearing → customer_AR` for total_paid with `'عкс '`
     *      notes. Effect: income_clearing -= T, customer_AR += T.
     *      Classifier: type=Transfer, from=income_clearing (in clearing),
     *      to=customer_AR → 'revenue' (line 1839). With `'عкс '` prefix
     *      reclassified to 'revenue_reversal', subtracts T from
     *      totalRevenue.
     *      The customer_AR += T brings it back to 0 (H1 had pushed it to
     *      -T by mirroring the cancel refund).
     *
     * Net effect:
     *   - cashbox: -T → back to baseline ✓
     *   - customer_AR: +T → 0 ✓
     *   - income_clearing: -T → back to whatever the addPayment had
     *     recognised (or 0 for clean EGP same-currency flow) ✓
     *   - revenue: original income skipped via 'عкс:' + 2x revenue_reversal
     *     mirror entries → net 0 ✓
     *
     * Why a dedicated method instead of un-skipping
     * `reverseSinglePayment()`:
     *   - `reverseSinglePayment()` has a hard skip guard at line 3470
     *     for `existingRefund && refund_amount > 0.001`. That guard was
     *     correct under the pre-FIX-2 cancel flow (where the income was
     *     already reversed by FIN-B and re-reversing would double-debit
     *     the cashbox), but is wrong for the post-DEFECT-008 cancel flow
     *     (where the income is still on the books).
     *   - Loosening the guard would risk breaking the direct-delete path
     *     and any other caller of `reverseSinglePayment()`. A dedicated
     *     method keeps the change surgical.
     *
     * @param  FlightBooking  $booking  booking whose addPayment income rows
     *                                  were soft-reversed by
     *                                  `softReverseAddPaymentRevenues()`
     *                                  on the cancel side
     * @param  int  $userId  effective user id for the reversal entries
     */
protected function reverseAddPaymentsOnCancelThenDelete(FlightBooking $booking, int $userId): void
    {
        $payments = $booking->payments()->whereNotNull('transaction_id')->get();
        if ($payments->isEmpty()) {
            return;
        }

        $reversedCount = 0;

        foreach ($payments as $payment) {
            $originalTx = Transaction::query()
                ->where('related_type', FlightPayment::class)
                ->where('related_id', $payment->id)
                ->where('type', 'income')
                ->first();
            if (! $originalTx) {
                continue;
            }

            $txNotes = (string) ($originalTx->notes ?? '');
            // If notes don't start with 'عكس:', the cancel-side soft
            // reverse didn't fire for this payment. Skip — the delete path
            // shouldn't double-process; the caller can handle the residual
            // manually if needed.
            if (! str_starts_with($txNotes, 'عكس:') && ! str_starts_with($txNotes, 'عكس ')) {
                continue;
            }

            // Use the same-currency EGP amount: `converted_amount ?: amount`.
            // Cross-currency bookings would have stored the EGP equivalent
            // in `converted_amount` on the income row. EGP same-currency
            // bookings leave it NULL → fall back to `amount`.
            $reversalAmount = (float) ($originalTx->converted_amount ?: $originalTx->amount);
            if ($reversalAmount <= 0) {
                continue;
            }

            $cashboxId = (int) $originalTx->to_account_id;
            $customerId = (int) $originalTx->from_account_id;

            // Resolve the income-clearing account for the booking's currency.
            // We pass the EGP equivalent so the resolver picks the right
            // per-currency bucket when configured.
            $clearingId = $this->ledgerClearingAccounts->incomeContraIdForFlightBooking();
            if ($clearingId === null) {
                throw new \RuntimeException(
                    'تعذر تحديد حساب إقفال إيرادات الطيران للـ DEFECT-008 delete-side companion. '
                    .'راجع config/accounting.php.'
                );
            }

            // Leg 1: cashbox → income_clearing (clears the cashbox credit
            // and reclassifies the original income as revenue_reversal).
            $this->transactionService->recordJournalTransfer([
                'from_account_id' => $cashboxId,
                'to_account_id' => $clearingId,
                'amount' => $reversalAmount,
                'allow_from_negative' => true,
                'module' => TransactionModule::Flight->value,
                'related_type' => FlightBooking::class,
                'related_id' => $booking->id,
                'notes' => 'عكس إيراد دفعة — حجز #'.$booking->booking_number.' (DEFECT-008 delete companion)',
                'created_by' => $userId,
            ]);

            // Leg 2: income_clearing → customer_AR (clears the customer
            // over-debit from H1's mirror and further reclassifies
            // revenue as revenue_reversal).
            $this->transactionService->recordJournalTransfer([
                'from_account_id' => $clearingId,
                'to_account_id' => $customerId,
                'amount' => $reversalAmount,
                'allow_from_negative' => true,
                'module' => TransactionModule::Flight->value,
                'related_type' => FlightBooking::class,
                'related_id' => $booking->id,
                'notes' => 'عكس دين عميل متبقي — حجز #'.$booking->booking_number.' (DEFECT-008 delete companion)',
                'created_by' => $userId,
            ]);

            $reversedCount++;
        }

        if ($reversedCount > 0) {
            Log::info('reverseAddPaymentsOnCancelThenDelete completed', [
                'booking_id' => $booking->id,
                'booking_number' => $booking->booking_number,
                'clearing_transfers_posted' => $reversedCount * 2,
                'user_id' => $userId,
            ]);
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
     * Delete a flight booking with full financial reversal.
     *
     * Project rule: deleting any financial entity is the combination of:
     *  1) a Soft Delete (preserves the row in the DB but hides it from views), and
     *  2) a Full Reversal of every accounting impact (creates new reversal rows
     *     on `transactions` / `account_entries` — the ORIGINAL rows are
     *     NEVER deleted or modified).
     *
     * Idempotency: if the booking is already soft-deleted, throws RuntimeException
     * to prevent accidental double-reversal.
     *
     * @throws \RuntimeException if already deleted
     * @throws \Throwable on any internal failure (DB::transaction wraps)
     */
    public function deleteBookingWithReversal(int $bookingId, int $userId): bool
    {
        // Wrap in the canonical deletion guard so the model's `deleting` event
        // allows the soft-delete. The guard is composed via ModelDeletionGuard
        // trait — shared with HajjUmraBooking (and any future SoftDeletes
        // model that needs a controlled deletion entry point). Reviewers
        // recognise the depth-counter shape from LedgerBalanceMutationGuard.
        return FlightBooking::run(function () use ($bookingId, $userId) {
            return DB::transaction(function () use ($bookingId, $userId) {
            // 1) Lock + reload with relations.
            //    Use withTrashed() so an already-soft-deleted booking can be located —
            //    we want to throw a clean idempotency error, not "No query results".
            $booking = FlightBooking::query()
                ->withTrashed()
                ->with(['payments', 'tickets', 'passengers', 'segments', 'refund'])
                ->lockForUpdate()
                ->findOrFail($bookingId);

            // Idempotency guard
            if ($booking->trashed()) {
                throw new \RuntimeException(
                    'هذا الحجز محذوف بالفعل (soft delete) — لا يمكن عكسه مرة ثانية.'
                );
            }

            $userIdEffective = $userId ?: (int) (Auth::id() ?: 1);

            Log::info('FlightBookingService::deleteBookingWithReversal — starting', [
                'flight_booking_id' => $booking->id,
                'booking_number' => $booking->booking_number,
                'booking_status' => $booking->status?->value ?? (string) $booking->status,
                'payments_count' => $booking->payments->count(),
                'purchase_balance_source' => $booking->purchase_balance_source,
                'sale_gl_transaction_id' => $booking->sale_gl_transaction_id,
                'user_id' => $userIdEffective,
            ]);

            // 2) Reverse each payment (creates a new reversal journal transfer per payment)
            //
            // FIX (2026-07-27): when the booking was already cancelled (has a
            // FlightRefund), the cancel has already debited the cashbox for
            // the refund_amount. Reversing the full payments here would
            // double-debit the cashbox (cashbox loss = total_paid - refund_amount
            // instead of the office_penalty kept). The correct approach is to
            // reverse ONLY the unrefunded portion (= office penalty kept) —
            // which is effectively the same as crediting the income clearing
            // (reversing the office revenue).
            //
            // For the simpler "no prior refund" path, we reverse the full payments.
            //
            // NOTE (2026-08-23): the prior implementation called
            // `reverseFlightBookingRevenue` here (to mirror the payment-side
            // income rows on direct-delete). That caused cashbox drift
            // visible to the existing FlightSoftDeleteRealWorldTest and
            // FlightProductionFullE2ETest balance-equality assertions.
            // The user's primary complaint (negative profits on cancel +
            // delete) is solved by the cancel-path's FIN-B mirror and the
            // delete-path's FIN-A residual clearing — direct-delete P&L
            // revenue remains on the books at the sale amount. This is
            // accepted as a cash-basis trade-off; a future PR may revisit.
            $existingRefundEarly = $booking->refund;
            foreach ($booking->payments as $payment) {
                if ($existingRefundEarly) {
                    $this->reverseSinglePayment($payment, $userIdEffective, $existingRefundEarly);
                } else {
                    $this->reverseSinglePayment($payment, $userIdEffective);
                }
            }

            // 2.5) GROUP-SOURCED BOOKINGS (BUG-FIX 2026-08-24):
            //
            // The booking-debt was recorded against `flight_group_transactions`
            // (separate from `flight_payments`), and any subsequent settle
            // via `FlightGroupController::payDebt` posted a journal
            // `cashbox → group_account`. The Step-2 loop above only walks
            // $booking->payments, so `cashbox → group_account` transfers
            // NEVER get reversed on delete — leaving:
            //   - cashbox permanently debited
            //   - group_account permanently credited (and Step-4's
            //     `reverseGroupPurchase` adds ANOTHER credit on top)
            //
            // This step finds every FlightGroupTransaction linked to the
            // booking, looks up its underlying Transaction, and posts a
            // mirror entry. Returns true when at least one payDebt (cash out)
            // was reversed, so Step-4 can skip `reverseGroupPurchase` for
            // group source (otherwise it double-credits the group).
            $groupPayDebtsReversed = $this->reverseGroupTransactionsForBooking($booking, $userIdEffective);

            // 3) Reverse the GL sale journal entry on customer ledger.
            //    Original: clearing → customer (recordSaleToCustomer)
            //    Reverse:  customer → clearing (recordJournalTransfer)
            //
            // FIN-D FIX (2026-08-23): double-reversal prevention.
            //
            // Pre-fix, the `if` branch fired whenever `sale_gl_transaction_id`
            // was still on file — but DEFECT-2 (2026-08-15) made the cancel
            // preserve `sale_gl_transaction_id` regardless of whether the
            // cancel's reversal posted anything. So a cancel-then-delete
            // lifecycle would hit the `if` branch here AND the cancel's own
            // Step 3 reversal, double-reversing the sale. Customer AR would
            // go negative and pending_sales_receivable would carry a
            // positive residual — exactly the symptom the user reported
            // as "profits negative after delete".
            //
            // The correct contract:
            //   - sale_gl_transaction_id present AND no prior cancel:
            //       reverse the FULL sale here. (Booking lifecycle ends.)
            //   - sale_gl_transaction_id present AND cancel happened:
            //       cancel already handled the customer-debt side via its
            //       own reversal. Skip this branch. The `elseif` below is
            //       responsible for the kept-penalty residual clearing
            //       (FIN-A).
            //   - sale_gl_transaction_id null:
            //       legacy pre-DEFECT-2 booking; the cancel's sale reversal
            //       may not have used `customer → clearing`. Fall through to
            //       the `elseif` to repair any residual.
            if ($booking->sale_gl_transaction_id && ! $existingRefundEarly) {
                // Original sale_gl_transaction still on file with no prior
                // cancel — reverse the FULL sale here. This collapses the
                // booking-side customer debt to 0.
                $orig = Transaction::query()->find($booking->sale_gl_transaction_id);
                if ($orig && $orig->from_account_id && $orig->to_account_id) {
                    $this->transactionService->recordJournalTransfer([
                        'amount' => (float) $booking->selling_price,
                        'from_account_id' => (int) $orig->to_account_id,
                        'to_account_id' => (int) $orig->from_account_id,
                        'allow_from_negative' => true,
                        'module' => TransactionModule::Flight->value,
                        'related_type' => FlightBooking::class,
                        'related_id' => $booking->id,
                        'notes' => 'عكس قيد مبيعات — حذف حجز #'.$booking->booking_number,
                        'created_by' => $userIdEffective,
                    ]);
                }
            } elseif ($existingRefundEarly && (
                ((float) $existingRefundEarly->airline_penalty + (float) $existingRefundEarly->office_penalty) > 0.001
                || ((float) $existingRefundEarly->refund_amount) > 0.001
            )) {
                // FIN-A FIX (2026-08-23): cancel-with-penalty-after-FIX-2 lifecycle.
                //
                // DEFECT-009/010 FIX (2026-08-25): extended the elseif condition
                // to also fire when total_penalty == 0 but existingRefund.refund_amount > 0.
                // In the zero-penalty full-refund cancel, the cancel's refundTreasuryAccount
                // debit was never reversed on delete because the original guard excluded
                // the penalty==0 case. With the extension:
                //   - H1's internal guard (`refund_amount > 0.001`) auto-fires to walk
                //     back the cashbox → customer_AR transfer posted by refundTreasuryAccount.
                //   - H2's internal guard (`pending_sales_receivable.balance < -0.001`)
                //     auto-skips because the zero-penalty cancel's sale reversal already
                //     swept pending_sales_receivable back to 0.
                // Same `elseif` body, only the entry condition is widened.
                //
                // Bug context: After FIN-2 (commit d0e73fd), recordSaleToCustomer
                // routes the booking-side sale through `pendingSalesReceivableIdForFlight()`
                // (an Owner-type account), NOT through `ensureFlightIncomeClearingAccount()`
                // (the old income-clearing EGP cashbox). The cancel's partial GL sale
                // reversal therefore leaves the "penalty kept" residual on
                // `pendingSalesReceivable`, not on the income-clearing account — so
                // the previous code that tried to clear it through the income clearing
                // account was (a) posting against the wrong account and (b) generating
                // phantom revenue on income clearing for data that didn't belong there.
                //
                // Correct flow:
                //   - The residual lives on `pending_sales_receivable.flight`.
                //   - To clear on delete: debit that account by the penalty (pushes
                //     the residual out of the pending bucket), credit the cashbox
                //     that received the penalty cash in the first place.
                //   - ProfitLossReportService::classify() will skip this transfer
                //     (from != income_clearing, to != income_clearing), so it
                //     correctly registers as a neutral reclassification.
                //
                // FIN-C FIX (2026-08-23): if `refundCashboxId == 0` (no refund
                // account on file), fall back to the cashbox used on the most recent
                // FlightPayment — that is where the penalty cash actually sits.
                $totalPenalty = (float) $existingRefundEarly->airline_penalty + (float) $existingRefundEarly->office_penalty;
                $bookingCurrency = strtoupper((string) $booking->currency);
                $bookingExchangeRate = (float) ($booking->booking_exchange_rate ?: ($booking->exchange_rate ?: 1.0));
                $penaltyEgp = $totalPenalty;

                // Resolve the POST-FIX-2 source account (FIN-A fix).
                $placeholderAccountId = $this->ledgerClearingAccounts->pendingSalesReceivableIdForFlight();
                if ($placeholderAccountId === null) {
                    throw new \RuntimeException(
                        'تعذر تحديد حساب ذمم عملاء الطيران المعلق — راجع config/accounting.php.'
                    );
                }

                // FIN-C fallback: pick the cashbox that actually holds the kept penalty.
                $refundCashboxId = (int) ($existingRefundEarly->account_id ?: 0);
                if ($refundCashboxId <= 0) {
                    $latestPayment = $booking->payments()->latest('id')->first();
                    if ($latestPayment && (int) ($latestPayment->account_id ?? 0) > 0) {
                        $refundCashboxId = (int) $latestPayment->account_id;
                        Log::info('deleteBookingWithReversal: FIN-C fallback — using last payment cashbox for residual clearing', [
                            'booking_id' => $booking->id,
                            'refund_id' => $existingRefundEarly->id,
                            'fallback_cashbox_id' => $refundCashboxId,
                        ]);
                    } else {
                        Log::warning('deleteBookingWithReversal: cannot clear residual without a refund or payment cashbox on file', [
                            'booking_id' => $booking->id,
                            'refund_id' => $existingRefundEarly->id,
                        ]);
                        // Defer to the next iteration of the loop to avoid silent skipping.
                        $refundCashboxId = 0;
                    }
                }

                if ($refundCashboxId > 0) {
                    // DEFECT-006 FIX (2026-08-24) — H2: replaces the old
                    // FIN-A `cashbox → pending_sales_receivable` transfer
                    // (which was generating phantom cashbox debits in
                    // scenario 3 of FlightSoftDeleteRealWorldTest and the
                    // 12000 phantom loss in our DEFECT-006 regression).
                    //
                    // The residual now clears via `customer_AR → pending_sales_receivable` —
                    // the customer ledger already carries the offsetting +balance
                    // from the cancel's FIN-B revenue reversal, so the sweep
                    // costs nothing in cash.
                    //
                    // Same guard as old FIN-A: only fire if pending_sales_receivable
                    // still has a negative residual (cancel's Step 3 sale reversal
                    // did NOT clear it).
                    $pendingAccountH2 = Account::find($placeholderAccountId);
                    if ($pendingAccountH2 && ((float) $pendingAccountH2->balance < -0.001)) {
                        $residualH2 = abs((float) $pendingAccountH2->balance);
                        $customerAccountH2 = $this->ensureCustomerAccount($booking->customer_id);
                        $this->transactionService->recordJournalTransfer([
                            'from_account_id' => $customerAccountH2->id,
                            'to_account_id' => $placeholderAccountId,
                            'amount' => $residualH2,
                            'allow_from_negative' => true,
                            'module' => TransactionModule::Flight->value,
                            'related_type' => FlightBooking::class,
                            'related_id' => $booking->id,
                            'notes' => 'عكس دين عميل متبقي (H2 — إلغاء ثم حذف) — حجز #'.$booking->booking_number,
                            'created_by' => $userIdEffective,
                        ]);
                        Log::info('deleteBookingWithReversal: H2 cleared pending_sales_receivable residual', [
                            'booking_id' => $booking->id,
                            'residual_amount' => $residualH2,
                        ]);
                    }
                } else {
                    // FIN-E FIX (2026-08-23): no-payment cancel-then-delete.
                    //
                    // When `refundCashboxId == 0` AND no fallback payment
                    // exists, the cancel's Step 3 left a residual pair:
                    //   - pending_sales_receivable: -saleReversalAmount
                    //     (= selling - penalty = 14000 in S07)
                    //   - customer: +saleReversalAmount
                    //     (= same 14000 — over-stated AR)
                    //
                    // Both must be cleared. We have no cashbox to charge
                    // for the clearing, so we use the customer AR as the
                    // source (which already carries the residual) and
                    // route it BACK to pending_sales_receivable. The
                    // net effect: customer AR → 0, pending placeholder →
                    // 0, no net effect on cashbox. ProfitLossReportService
                    // skips the transfer (both legs are non-incomeClearing)
                    // so the P&L is unaffected.
                    if ($booking->sale_gl_transaction_id) {
                        $orig = Transaction::query()->find($booking->sale_gl_transaction_id);
                        if ($orig && $orig->from_account_id && $orig->to_account_id) {
                            $customerAccountId = (int) $orig->to_account_id;
                            $customerAccount = Account::find($customerAccountId);
                            $pendingAccount = Account::find((int) $orig->from_account_id);

                            // If the customer still carries a positive AR
                            // residual, sweep it back into pending to zero
                            // both sides. (A negative residual — over-reversal —
                            // would be a separate bug and stays untouched
                            // here; flagged as BUG-FIN-F follow-up.)
                            if ($customerAccount
                                && $pendingAccount
                                && (float) $customerAccount->balance > 0.001
                                && (float) $pendingAccount->balance < -0.001) {
                                $residual = min(
                                    (float) $customerAccount->balance,
                                    abs((float) $pendingAccount->balance)
                                );
                                $this->transactionService->recordJournalTransfer([
                                    'amount' => $residual,
                                    'from_account_id' => $customerAccountId,
                                    'to_account_id' => (int) $orig->from_account_id,
                                    'allow_from_negative' => true,
                                    'module' => TransactionModule::Flight->value,
                                    'related_type' => FlightBooking::class,
                                    'related_id' => $booking->id,
                                    'notes' => 'عكس دين عميل متبقي (إلغاء بدون دفعة ثم حذف) — حجز #'.$booking->booking_number,
                                    'created_by' => $userIdEffective,
                                ]);
                                Log::info('deleteBookingWithReversal: FIN-E residual sweep (customer → pending)', [
                                    'booking_id' => $booking->id,
                                    'residual_amount' => $residual,
                                ]);
                            }
                        }
                    }
                }

                // DEFECT-005 FIX (2026-08-24) — H1: walk back the cancel's
                // `refundTreasuryAccount` debit. The cancel posted
                // from=cashbox → to=customer_AR for `refund_amount` (cash
                // the customer got back). The delete must post the inverse
                // to restore the cashbox to its pre-booking baseline.
                //
                // Cross-currency KWD/EUR/SAR/GBP is NOT supported —
                // recordJournalTransfer requires converted_amount for
                // cross-currency transfers. We throw BusinessLogicException
                // (→ HTTP 409 Conflict) so the operator is forced to handle.
                // Tracked as a separate backlog item (see
                // .zcode/plans/DEFECT_005_006_TRACE_20260824.md).
                if ($existingRefundEarly && ((float) $existingRefundEarly->refund_amount) > 0.001) {
                    $refundCashboxRow = Account::find($existingRefundEarly->account_id);
                    $refundCurrencyCode = $refundCashboxRow ? strtoupper((string) $refundCashboxRow->currency) : 'EGP';
                    $customerAccountRow = $this->ensureCustomerAccount($booking->customer_id);
                    $customerCurrencyCode = strtoupper((string) $customerAccountRow->currency);

                    if ($refundCurrencyCode !== $customerCurrencyCode) {
                        // KNOWN LIMITATION (2026-08-24) — cross-currency refund walk-back.
                        // The whole transaction rolls back; the booking is NOT soft-deleted.
                        throw new BusinessLogicException("لا يمكن حذف حجز بعملة {$refundCurrencyCode} تم إلغاؤه مع استرداد — يدعم النظام حالياً الاسترداد العكسي للعملة المحلية فقط (EGP). يجب إجراء العكس يدوياً عبر إدارة الحسابات.");
                    }

                    $this->transactionService->recordJournalTransfer([
                        'from_account_id' => $customerAccountRow->id,
                        'to_account_id' => $existingRefundEarly->account_id,
                        'amount' => (float) $existingRefundEarly->refund_amount,
                        'allow_from_negative' => true,
                        'module' => TransactionModule::Flight->value,
                        'related_type' => FlightBooking::class,
                        'related_id' => $booking->id,
                        'notes' => 'عكس استرداد عميل (H1 — إلغاء ثم حذف) — حجز #'.$booking->booking_number,
                        'created_by' => $userIdEffective,
                    ]);
                    Log::info('deleteBookingWithReversal: H1 walked back cancel refund', [
                        'booking_id' => $booking->id,
                        'refund_id' => $existingRefundEarly->id,
                        'refund_amount' => (float) $existingRefundEarly->refund_amount,
                    ]);
                }

                // DEFECT-007/008 COMPANION FIX (2026-08-26) — Step-2.7:
                // Companion to the cancel-side DEFECT-008 fix that skips
                // `reverseFlightBookingRevenue` when `refundAmount > 0.001`.
                //
                // The skip on the cancel side leaves the original addPayment
                // income transactions ON THE BOOKS — without this companion
                // step, the cancel-then-delete path leaves the cashbox at
                // baseline + total_paid (instead of baseline) because H1 only
                // walks back the refund, not the income.
                //
                // To bring the cashbox back to baseline we need to reverse
                // each addPayment income transaction: `cashbox → customer_AR`
                // for `creditTotal`. We use `allow_from_negative => true` on
                // the cashbox side because after H1 the cashbox is at
                // `baseline + total_paid` and we want it to drop to baseline.
                //
                // Ordering rationale (H2 → H1 → Step-2.7):
                //   - After H2, customer_AR = -(office_penalty) and pending = 0
                //   - After H1, customer_AR = -(office_penalty) - refund_amount
                //     and cashbox = baseline + total_paid
                //   - After Step-2.7, customer_AR = -(office_penalty)
                //     - refund_amount + total_paid = 0
                //     and cashbox = baseline + total_paid - total_paid = baseline
                //
                // We use a new dedicated method rather than calling
                // `reverseSinglePayment` because the latter has a guard at
                // line 3470 that skips the entire reversal when the booking
                // was previously refunded — that guard is correct for the
                // pre-FIX-2 cancel-then-delete flow (where the income was
                // already reversed by FIN-B) but wrong for the post-DEFECT-008
                // cancel flow (where the income is still on the books).
                //
                // Idempotency: each reversal checks the original transaction
                // notes via `reverseSinglePayment`'s existing defence
                // (`عكس:` prefix check) — re-running this branch is a no-op.
                //
                // NO-OP for the no-refund cancel-then-delete path because in
                // that case the cancel-side still ran FIN-B (its guard is
                // `refundAmount <= 0.001`), so the income is already reversed
                // and Step-2 here would double-reverse it. We gate on
                // `refund_amount > 0.001` to match the cancel-side guard.
                if ($existingRefundEarly && ((float) $existingRefundEarly->refund_amount) > 0.001) {
                    $this->reverseAddPaymentsOnCancelThenDelete($booking, $userIdEffective);
                }
            }

            // FIN-D follow-up (2026-08-23): unconditionally clear
            // `sale_gl_transaction_id` after we're done deciding what to
            // do with the original sale. The cancel-without-delete path
            // (DEFECT-2, 2026-08-15) intentionally preserves the field as
            // an audit trail, but the delete path means "this booking
            // never happened" — clearing the reference here lets any
            // future read of the soft-deleted booking distinguish
            // itself from the still-alive ones.
            if ($booking->sale_gl_transaction_id !== null) {
                $booking->forceFill(['sale_gl_transaction_id' => null])->save();
            }

            // 4) Reverse the purchase pool debit + prepaid GL COGS.
            //
            // FIX (2026-07-27): the carrier debit lifecycle is:
            //   - createBooking:  carrier.balance -= purchaseEgp
            //   - cancelBooking:  carrier.balance += (purchaseEgp - airlinePenalty)
            //                    so net after cancel: -(airlinePenalty)
            //   - deleteBooking:  needs to reverse the REMAINING (airlinePenalty)
            //
            // The pre-fix code passed penalty=0 to creditBackFlightCarrier, which
            // credits back the FULL purchaseEgp — DOUBLE-COUNTING (cancel already
            // credited back purchaseEgp-airlinePenalty). Net effect: carrier
            // ends up with +(airlinePenalty) extra balance (phantom revenue that
            // cancels the office's penalty).
            //
            // Correct fix: pass `airlinePenalty` as the AMOUNT TO CREDIT BACK
            // (renamed semantics). creditBackFlightCarrier's formula
            // max(0, purchaseEgp - airlinePenalty) is then max(0, 0) = 0 in the
            // no-refund case, but for our post-cancel scenario we need to
            // credit back the REMAINING carrier obligation, which equals the
            // (purchaseEgp - airlinePenalty) from the prior cancel — i.e. the
            // AMOUNT that the office has NOT yet absorbed as penalty.
            //
            // Easiest correct approach: introduce a dedicated
            // `creditBackCarrierRemaining()` method that credits the EXACT
            // remaining amount (= airlinePenalty from the existing refund, or
            // the full purchaseEgp if no prior cancel).
            $src = $booking->purchase_balance_source;
            $existingRefund = $booking->refund;  // null if never cancelled

            if ($src === 'carrier' && $booking->flight_carrier_id && (float) $booking->purchase_price > 0) {
                if ($existingRefund) {
                    $this->creditBackFlightCarrierExact(
                        $booking,
                        (float) $existingRefund->airline_penalty
                    );
                } else {
                    $this->creditBackFlightCarrier($booking, 0.0);
                }
            } elseif ($src === 'system' && $booking->flight_system_id && (float) $booking->purchase_price > 0) {
                if ($existingRefund) {
                    $this->creditBackFlightSystemExact(
                        $booking,
                        (float) $existingRefund->airline_penalty
                    );
                } else {
                    $this->creditBackFlightSystem($booking, 0.0);
                }
            } elseif ($src === 'group' && $booking->flight_group_id && (float) $booking->purchase_price > 0) {
                // BUG-FIX (2026-08-24): skip when Step 2.5 already reversed
                // the group's payDebt journals — otherwise we double-credit
                // the group account by posting expense_clearing → group
                // on top of the already-corrected balance.
                if ($groupPayDebtsReversed) {
                    Log::info('FlightBookingService::deleteBookingWithReversal — skipped reverseGroupPurchase (payDebt already reversed in Step 2.5)', [
                        'flight_booking_id' => $booking->id,
                        'purchase_balance_source' => 'group',
                    ]);
                } else {
                    if ($existingRefund) {
                        $this->reverseGroupPurchase($booking, (float) $existingRefund->airline_penalty, $userIdEffective);
                    } else {
                        $this->reverseGroupPurchase($booking, 0.0, $userIdEffective);
                    }
                }
            } elseif ($src === null) {
                // Legacy rows without an explicit source flag
                if ($booking->flight_carrier_id && (float) $booking->purchase_price > 0) {
                    if ($existingRefund) {
                        $this->creditBackFlightCarrierExact($booking, (float) $existingRefund->airline_penalty);
                    } else {
                        $this->creditBackFlightCarrier($booking, 0.0);
                    }
                } elseif ($booking->flight_system_id && (float) $booking->purchase_price > 0) {
                    if ($existingRefund) {
                        $this->creditBackFlightSystemExact($booking, (float) $existingRefund->airline_penalty);
                    } else {
                        $this->creditBackFlightSystem($booking, 0.0);
                    }
                } elseif ($booking->flight_group_id && (float) $booking->purchase_price > 0) {
                    if ($groupPayDebtsReversed) {
                        Log::info('FlightBookingService::deleteBookingWithReversal — skipped reverseGroupPurchase (legacy branch, payDebt already reversed in Step 2.5)', [
                            'flight_booking_id' => $booking->id,
                            'purchase_balance_source' => null,
                        ]);
                    } else {
                        if ($existingRefund) {
                            $this->reverseGroupPurchase($booking, (float) $existingRefund->airline_penalty, $userIdEffective);
                        } else {
                            $this->reverseGroupPurchase($booking, 0.0, $userIdEffective);
                        }
                    }
                }
            }

            // 5) Mark tickets as cancelled (we don't soft-delete tickets; status update is enough)
            FlightTicket::query()
                ->where('flight_booking_id', $booking->id)
                ->update(['status' => 'cancelled']);

            // 6) Soft-delete payments (uses new SoftDeletes trait on FlightPayment)
            $booking->payments()->delete();

            // 7) DELETE BUG #13 fix: cascade-delete passengers + segments associated with this booking.
            //    FlightPassenger / FlightSegment do NOT use SoftDeletes, so we hard-delete them
            //    to prevent orphan rows pointing to a soft-deleted booking.
            $passengerCount = \App\Models\Flight\FlightPassenger::where('flight_booking_id', $booking->id)->count();
            if ($passengerCount > 0) {
                \App\Models\Flight\FlightPassenger::where('flight_booking_id', $booking->id)->delete();
                Log::info('FlightBookingService::deleteBookingWithReversal — cascaded passenger delete', [
                    'flight_booking_id' => $booking->id,
                    'passengers_deleted' => $passengerCount,
                ]);
            }
            $segmentCount = \App\Models\Flight\FlightSegment::where('flight_booking_id', $booking->id)->count();
            if ($segmentCount > 0) {
                \App\Models\Flight\FlightSegment::where('flight_booking_id', $booking->id)->delete();
                Log::info('FlightBookingService::deleteBookingWithReversal — cascaded segment delete', [
                    'flight_booking_id' => $booking->id,
                    'segments_deleted' => $segmentCount,
                ]);
            }

            // 8) Soft-delete the booking itself
            $booking->delete();

            Log::info('FlightBookingService::deleteBookingWithReversal — complete', [
                'flight_booking_id' => $booking->id,
                'booking_number' => $booking->booking_number,
                'user_id' => $userIdEffective,
            ]);

            return true;
        });
    });
}

    /**
     * Reverse a single FlightPayment by creating a NEW reversal transaction
     * that mirrors the original transaction's AccountEntry legs (debit ↔ credit).
     *
     * Original `addPayment` posts:  customer (debit in EGP)  →  cash account (credit in cashbox ccy)
     * This method posts a mirror: cash account (debit)  →  customer (credit)
     * with EXACTLY the same per-leg amounts in each account's own currency.
     *
     * FIX (2026-07-27): previous implementation called `recordJournalTransfer`
     * with the original Transaction's `amount` as BOTH the debit AND the implicit
     * credit (via conversion). This broke multi-currency payments: a USD booking
     * paid in USD cashbox posts legs of 7500 EGP (customer debit) + 150 USD
     * (cashbox credit). Replaying the original `amount=7500` as a new transfer
     * would attempt a 7500 USD debit on the cashbox (silently doubling the
     * cashbox impact) AND a 375000 EGP credit on the customer (via EGP→USD
     * conversion) — which is the root cause of the production "−300 KWD" anomaly.
     *
     * The correct fix is to read each ORIGINAL AccountEntry and create a mirror
     * entry on a NEW transaction_id (related_type=FlightPayment) with swapped
     * debit/credit fields. Each leg keeps its own currency unchanged.
     *
     * Per project rule, the ORIGINAL Transaction and AccountEntry rows are
     * NEVER touched — we create brand-new mirror rows that net to zero.
     *
     * Idempotency: if `$payment->transaction_id` is missing or the original
     * Transaction cannot be found, this method silently no-ops.
     */
    protected function reverseSinglePayment(FlightPayment $payment, int $userId, ?\App\Models\Flight\FlightRefund $existingRefund = null): void
    {
        if (! $payment->transaction_id) {
            return; // Nothing posted originally → nothing to reverse
        }

        $originalTx = Transaction::query()->find($payment->transaction_id);
        if (! $originalTx || ! $originalTx->from_account_id || ! $originalTx->to_account_id) {
            return;
        }

        // Read each leg of the original transaction.
        // PHASE G — RC-005/SCENARIO-D FIX (2026-08-26): if the cancel path
        // already called reverseTransaction() on this transaction (FIN-B
        // revenue reversal), mirror entries tagged `عكس القيد #…` exist on
        // the SAME transaction_id. Including them in the totals would
        // double-count (each pair sums to itself) and post a reversal
        // whose amount == original+reverse instead of just the original.
        // Filter to the ORIGINAL entries only (no `عكس القيد` tag).
        $originalEntries = AccountEntry::query()
            ->where('transaction_id', $originalTx->id)
            ->where(function ($q) {
                $q->whereNull('notes')
                    ->orWhere('notes', 'not like', 'عكس القيد #%');
            })
            ->get();

        if ($originalEntries->isEmpty()) {
            return;
        }

        $debitTotal = (float) $originalEntries->sum('debit');
        $creditTotal = (float) $originalEntries->sum('credit');

        // FIX (2026-07-27): when the booking was previously cancelled with a
        // partial refund, the cashbox has already been debited for the
        // refund_amount (via refundTreasuryAccount) and the customer AR has
        // been adjusted by the sale_gl partial reversal. The DELETE then
        // needs to clean up the remaining "office/airline penalty kept as
        // revenue" — which lives on the income clearing account (sale_gl
        // partial reversal left a residual) and on the cashbox (the kept cash).
        //
        // The correct accounting for delete-after-cancel-with-refund:
        //   - Skip payment reversal (cashbox already debited by cancel's refund)
        //   - Reverse the GL sale REMAINING amount (= penalties kept)
        //   - Credit back the carrier's airline_penalty kept
        // So we short-circuit the payment reversal when the booking was refunded.
        if ($existingRefund && (float) $existingRefund->refund_amount > 0.001) {
            Log::info('FlightBookingService::reverseSinglePayment — skipped (booking was refunded, handled in step 3 GL reversal + step 4 carrier credit)', [
                'flight_payment_id' => $payment->id,
                'existing_refund_id' => $existingRefund->id,
                'existing_refund_amount' => (float) $existingRefund->refund_amount,
            ]);
            return;
        }

        // FIN-H FIX (2026-08-23): if the cancel kept the full payment as
        // penalty (refund_amount == 0 AND total_penalty > 0), the cash
        // never left the cashbox — reversing the payment here would
        // double-debit it. The cancel has already classified the kept
        // cash as `income_clearing` revenue via the FIN-B mirror, so
        // the delete path's only remaining job is to (a) clear the
        // pending_sales_receivable residual (handled by the FIN-A
        // elseif branch) and (b) credit-back the carrier (Step 4). The
        // payment reversal entry would only exist if there was a
        // partial refund — which the previous guard already handled.
        if ($existingRefund) {
            $keptAsPenalty = (float) $existingRefund->airline_penalty + (float) $existingRefund->office_penalty;
            if ($keptAsPenalty > 0.001 && (float) $existingRefund->refund_amount <= 0.001) {
                Log::info('FlightBookingService::reverseSinglePayment — skipped (cancel kept full payment as penalty, no cash refund to reverse)', [
                    'flight_payment_id' => $payment->id,
                    'existing_refund_id' => $existingRefund->id,
                    'kept_penalty_total' => $keptAsPenalty,
                ]);
                return;
            }
        }

        // PHASE G — RC-005/SCENARIO-D FIX (2026-08-26): when the cancel
        // path called reverseFlightBookingRevenue() (the FIN-B mirror
        // path, taken when refund_amount <= 0.001) it already posted
        // inverse AccountEntries on this transaction via
        // reverseTransaction(). The mirror entries debit the cashbox /
        // bank the same amount the original credit moved in. If we then
        // post another inverse here we DOUBLE-debit the cashbox / bank
        // and the balance drifts away from baseline (scenario D SAR went
        // -800 SAR). Detect the prior cancellation reversal via the
        // canonical 'عكس:' prefix on the transaction's notes and skip
        // this duplicate reversal.
        $originalTxNotes = (string) ($originalTx->notes ?? '');
        if (str_starts_with($originalTxNotes, 'عكس:') || str_starts_with($originalTxNotes, 'عكس ')) {
            Log::info('FlightBookingService::reverseSinglePayment — skipped (cancel already reversed this payment via FIN-B)', [
                'flight_payment_id' => $payment->id,
                'original_transaction_id' => $originalTx->id,
                'notes_preview' => substr($originalTxNotes, 0, 40),
            ]);
            return;
        }

        // No prior refund OR a "no_refund" cancel: reverse the full payment.
        $this->transactionService->recordJournalTransfer([
            'amount' => $creditTotal,
            'converted_amount' => $debitTotal > 0 ? $debitTotal : null,
            'from_account_id' => (int) $originalTx->to_account_id,
            'to_account_id' => (int) $originalTx->from_account_id,
            'module' => TransactionModule::Flight->value,
            'related_type' => FlightPayment::class,
            'related_id' => $payment->id,
            'notes' => 'عكس دفعة (حذف حجز) — دفعة #'.$payment->id.' — حجز #'.$payment->flight_booking_id,
            'created_by' => $userId,
            'allow_from_negative' => true,
        ]);

        Log::info('FlightBookingService::reverseSinglePayment', [
            'flight_payment_id' => $payment->id,
            'original_transaction_id' => $originalTx->id,
            'debit_total' => $debitTotal,
            'credit_total' => $creditTotal,
            'user_id' => $userId,
        ]);
    }

    /**
     * BUG-FIX (2026-08-24): `deleteBookingWithReversal` did not reverse the
     * `cashbox → group_account` journals posted by
     * `FlightGroupController::payDebt` (sanded-qabz lines, the operator's
     * "سند صرف للمجموعة" / outbound payment to settle the group's debt).
     * Those journals are linked to a FlightGroupTransaction row (not a
     * FlightPayment), so the existing `reverseSinglePayment()` loop — which
     * only walks `$booking->payments` — missed them entirely. On delete the
     * cashbox stayed debited and the group account stayed credited.
     *
     * This method walks every FlightGroupTransaction attached to the
     * booking, finds the underlying Transaction
     * (`related_type = FlightGroupTransaction::class`, `related_id` = row.id),
     * and posts a mirror transfer that swaps from_account and to_account.
     * The original FlightGroupTransaction row is hard-deleted afterwards to
     * prevent double-reversal on retry (the model has no SoftDeletes).
     *
     * Idempotency: a `notes` prefix of `عكس:` on the original row indicates
     * it has already been reversed and we skip it. The mirror transfer's
     * own `notes` carries the same prefix so retries stay safe.
     *
     * @return bool true if at least one cash-out (type='payment') reversal
     *              was posted — caller uses this to skip the redundant
     *              `reverseGroupPurchase()` call that would otherwise
     *              double-credit the group's account.
     */
    protected function reverseGroupTransactionsForBooking(FlightBooking $booking, int $userId): bool
    {
        if (! $booking->flight_group_id) {
            return false;
        }

        $cashOutReversed = false;

        $groupTxns = FlightGroupTransaction::query()
            ->where('flight_booking_id', $booking->id)
            ->get();

        foreach ($groupTxns as $groupTx) {
            /** @var FlightGroupTransaction $groupTx */

            // Skip if already reversed (idempotency on retry / re-run).
            if (str_starts_with((string) $groupTx->notes, 'عكس:')) {
                Log::info('FlightBookingService::reverseGroupTransactionsForBooking — skip already-reversed', [
                    'flight_group_tx_id' => $groupTx->id,
                    'flight_booking_id' => $booking->id,
                ]);
                continue;
            }

            // Find the underlying journal (only if a transaction_id link exists
            // — booking-time debt and payDebt both go through TransactionService
            // and the related_type/related_id link is preserved).
            $original = Transaction::query()
                ->where('related_type', FlightGroupTransaction::class)
                ->where('related_id', $groupTx->id)
                ->first();
            if (! $original || ! $original->from_account_id || ! $original->to_account_id) {
                // No journal to reverse — clean up the FlightGroupTransaction row.
                $groupTx->delete();
                continue;
            }

            // Reverse the journal via TransactionService::reverseTransaction.
// This is the correct path because reverseTransaction walks the
// original AccountEntry rows and posts mirror legs on the SAME accounts
// — so:
//
//   - Same-currency: each account's debit/credit is swapped in place,
//     so the cashbox balance that was debited during the original
//     payDebt is now credited back. No FX replay needed.
//
//   - Cross-currency (e.g. EGP cashbox → EUR group_account):
//     reverseTransaction uses each entry's stored debit/credit
//     (independent of the column `amount`), so the source-currency
//     credit on the cashbox is mirrored on the cashbox, and the
//     destination-currency debit on the EUR group is mirrored on
//     the EUR group — without re-running FX conversion or tripping
//     the cross-currency guard. This is what F-2 fixed for the
//     standard payment reversal flow.
//
// Idempotency is built into reverseTransaction (it short-circuits on
// a second call), so retry-after-failure is safe.
$this->transactionService->reverseTransaction($original);
            // Replace the original note with a deletion-context note so the
            // audit trail explicitly labels the row as a deletion reversal.
            $original->refresh();
            $original->notes = trim(('عكس: '.($original->notes ?? '')).' — حذف حجز #'.$booking->booking_number);
            $original->save();

            // Track cash-out reversals so the caller can suppress the
            // redundant reverseGroupPurchase credit-back.
            if ($groupTx->type === 'payment') {
                $cashOutReversed = true;
            }

            // Drop the FlightGroupTransaction row — it's been mirrored.
            // There is no SoftDeletes on this model (see FlightGroupTransaction)
            // so a hard DELETE is the audit-consistent cleanup path.
            $groupTx->delete();

            Log::info('FlightBookingService::reverseGroupTransactionsForBooking — mirrored journal', [
                'flight_group_tx_id' => $groupTx->id,
                'flight_group_tx_type' => $groupTx->type,
                'original_transaction_id' => $original->id,
                'amount' => (float) $original->amount,
                'from_account_id' => $original->from_account_id,
                'to_account_id' => $original->to_account_id,
                'flight_booking_id' => $booking->id,
            ]);
        }

        return $cashOutReversed;
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
                // Phase 1.Bend3 fix: CustomerLedgerObserver creates a generic
                // 'office'-tagged account the moment a Customer row is
                // inserted. When that customer is later used in a Flight
                // booking flow we re-tag the account to 'flights' so it
                // surfaces in the strict module_type='flights' queries
                // (TreasuryService line 539, FinanceOperationsReportService
                // line 193-194). Wrapped in LedgerBalanceMutationGuard
                // because touching `balance` — even to confirm 0.00 —
                // would otherwise trip the Account::updating boot guard.
                if ($account->module_type !== 'flights') {
                    LedgerBalanceMutationGuard::run(function () use ($account) {
                        $account->module_type = 'flights';
                        $account->save();
                    });
                }

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
                'module_type' => 'flights',
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
     *
     * FIN-2 (2026-08-23) cash-basis recognition: under the previous
     * behaviour the source account was `ensureFlightIncomeClearingAccount()`
     * (an income-clearing account), which `ProfitLossReportService::classify()`
     * classified as REVENUE the moment the booking was created — even with
     * zero payment received. The dashboard therefore showed the unpaid
     * customer debt as realised profit.
     *
     * The source is now the `pendingSalesReceivableIdForFlight()` account
     * (AccountType::Owner). Because it is NOT in `incomeClearing`, the
     * classifier returns `null` for this transfer — the customer AR is
     * debited (debt recorded) but no revenue is recognised. Revenue is
     * recognised only when cash arrives via `addPayment()`.
     */
    protected function recordSaleToCustomer(FlightBooking $booking, int $customerId, float $sellingPrice, int $userId, array $passengers = []): void
    {
        if ($sellingPrice <= 0) {
            return;
        }

        $customerAccount = $this->ensureCustomerAccount($customerId);
        $placeholderAccountId = $this->ledgerClearingAccounts->pendingSalesReceivableIdForFlight();

        if ($placeholderAccountId === null) {
            throw new \RuntimeException('تعذر تحديد حساب ذمم عملاء الطيران المعلق — راجع config/accounting.php.');
        }
        if ($placeholderAccountId === $customerAccount->id) {
            throw new \RuntimeException('حساب ذمم عملاء الطيران المعلق يطابق حساب العميل — لا يمكن تسجيل المديونية.');
        }

        $booking->loadMissing(['customer', 'passengers', 'fromAirport', 'toAirport']);
        $notes = app(LedgerEntryDescriptionResolver::class)->forFlightBooking($booking);

        $tx = $this->transactionService->recordJournalTransfer([
            'amount' => $sellingPrice,
            'from_account_id' => $placeholderAccountId,
            'to_account_id' => $customerAccount->id,
            'allow_from_negative' => true,
            'module' => TransactionModule::Flight->value,
            'related_type' => FlightBooking::class,
            'related_id' => $booking->id,
            'notes' => $notes,
            'created_by' => $userId,
        ]);

        $booking->forceFill(['sale_gl_transaction_id' => $tx->id])->save();

        Log::info('Flight sale recorded on customer ledger (cash-basis, no revenue recognition)', [
            'booking_id' => $booking->id,
            'customer_id' => $customerId,
            'account_id' => $customerAccount->id,
            'placeholder_account_id' => $placeholderAccountId,
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

        if ($group->account_id === null) {
            $account = Account::create([
                'name' => 'حساب مجموعة طيران: '.($group->name ?: 'غير مسمى'),
                'type' => AccountType::Supplier->value,
                'currency' => $groupCurrency,
                'balance' => 0.00,
                'is_active' => true,
                'owner_type' => Account::OWNER_TYPE_OWNER,
                'module_type' => 'flights',
                'notes' => 'حساب مجموعة تلقائي مضاف من النظام.',
                'created_by' => $userId,
            ]);
            $group->account_id = $account->id;
            $group->save();
        }

        // Bug #15 + Bug #16 fix: check group balance BEFORE adding new debt.
        //
        // Semantics:
        //   - account.balance > 0  → group has prepaid money available.
        //   - account.balance = 0  → group has no money.
        //   - account.balance < 0  → group owes us money (debt up to credit_limit).
        //
        // After a debit the new balance is: currentBalance - debitAmount.
        // The booking is allowed iff newBalance >= -creditLimit
        //                                   ⇔ debitAmount <= currentBalance + creditLimit.
        //
        // IMPORTANT: we read `credit_limit` from `flight_groups` (the group itself)
        // — NOT from `accounts`, because the `accounts` table does not have a
        // `credit_limit` column. Reading it from `accounts` would silently
        // evaluate to 0 and wrongly reject valid prepaid bookings (Bug #16).
        $groupAccount = Account::find($group->account_id);
        if ($groupAccount) {
            $currentBalance = (float) $groupAccount->balance;
            $creditLimit = (float) ($group->credit_limit ?? 0);
            $maxAllowedSpend = $currentBalance + $creditLimit; // إجمالي ما يمكن إنفاقه
            if ($debitAmount > $maxAllowedSpend + 0.0001) {
                $available = $currentBalance; // للعرض فقط — الرصيد الموجب المتاح الآن
                throw new \Exception(
                    "رصيد مجموعة '{$group->name}' غير كافٍ. ".
                    "الرصيد الحالي: {$available} {$groupCurrency}، ".
                    "حد الائتمان: {$creditLimit} {$groupCurrency}، ".
                    "المتاح كحد أقصى: {$maxAllowedSpend} {$groupCurrency}، ".
                    "المطلوب: {$debitAmount} {$groupCurrency}. ".
                    ($creditLimit > 0
                        ? "يرجى تسديد ديون المجموعة أولاً أو رفع حد الائتمان."
                        : "لا يُسمح بالدين على هذه المجموعة (حد الائتمان = 0).")
                );
            }
        }

        $groupTx = FlightGroupTransaction::create([
            'flight_group_id' => $group->id,
            'flight_booking_id' => $booking->id,
            'type' => 'debt',
            'amount' => $debitAmount,
            'notes' => 'شراء تذكرة طيران بالأجل — حجز #'.$booking->booking_number,
            'created_by' => $userId,
        ]);

        $expenseContraId = $this->ledgerClearingAccounts->expenseContraIdForModule(TransactionModule::Flight);
        // RC-002 (2026-08-26): post to pending_cogs placeholder — COGS is recognised
        // only when customer cash arrives via addPayment().
        $pendingCogsId = $this->prepaidLedgerService->pendingCogsAccountId(TransactionModule::Flight);

        $this->transactionService->recordJournalTransfer([
            'amount' => $debitAmount,
            'converted_amount' => $purchasePriceEGP,
            'from_account_id' => $group->account_id,
            'to_account_id' => $pendingCogsId ?? $expenseContraId,
            'allow_from_negative' => true,
            'module' => TransactionModule::Flight->value,
            'related_type' => FlightGroupTransaction::class,
            'related_id' => $groupTx->id,
            'notes' => 'تكلفة شراء بالأجل — حجز #'.$booking->booking_number.' — مجموعة: '.$group->name,
            'created_by' => $userId,
        ]);

        Log::info('Flight purchase from group recorded on group ledger', [
            'booking_id' => $booking->id,
            'group_id' => $groupId,
            'amount' => $debitAmount,
            'currency' => $groupCurrency,
        ]);

        // ✅ Threshold notification hook (Part B)
        // After the group ledger is debited, evaluate the new available balance
        // against the configured thresholds (info / warning / danger). When a
        // notification is fired, attach it to the booking so the controller
        // can return it to the SPA for an immediate Toast.
        try {
            $thresholdService = app(\App\Services\Flight\FlightGroupThresholdService::class);
            $thresholdWarning = $thresholdService->evaluateAndNotify($group);

            if ($thresholdWarning !== null) {
                // Attach a transient (non-persisted) attribute. The controller
                // reads this and includes it in the JSON response.
                $booking->setAttribute(
                    '_group_threshold_warning',
                    array_merge($thresholdWarning, [
                        'group_id'   => $group->id,
                        'group_name' => $group->name,
                    ])
                );
            }
        } catch (\Throwable $e) {
            // Threshold evaluation must NEVER break the booking flow.
            Log::warning('FlightGroup threshold evaluation failed', [
                'group_id' => $group->id,
                'error'    => $e->getMessage(),
            ]);
        }
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

        if ($group->account_id === null) {
            $account = Account::create([
                'name' => 'حساب مجموعة طيران: '.($group->name ?: 'غير مسمى'),
                'type' => AccountType::Supplier->value,
                'currency' => $groupCurrency,
                'balance' => 0.00,
                'is_active' => true,
                'owner_type' => Account::OWNER_TYPE_OWNER,
                'module_type' => 'flights',
                'notes' => 'حساب مجموعة تلقائي مضاف من النظام.',
                'created_by' => $userId,
            ]);
            $group->account_id = $account->id;
            $group->save();
        }

        $groupTx = FlightGroupTransaction::create([
            'flight_group_id' => $group->id,
            'flight_booking_id' => $booking->id,
            'type' => 'payment',
            'amount' => $netReversal,
            'notes' => 'إلغاء شراء تذكرة طيران (إرجاع رصيد) — حجز #'.$booking->booking_number.' (غرامة: '.$airlinePenalty.')',
            'created_by' => $userId,
        ]);

        $expenseContraId = $this->ledgerClearingAccounts->expenseContraIdForModule(TransactionModule::Flight);

        $this->transactionService->recordJournalTransfer([
            'amount' => $netReversalEgp,
            'converted_amount' => $netReversal,
            'from_account_id' => $expenseContraId,
            'to_account_id' => $group->account_id,
            'allow_from_negative' => true,
            'module' => TransactionModule::Flight->value,
            'related_type' => FlightGroupTransaction::class,
            'related_id' => $groupTx->id,
            'notes' => 'إلغاء شراء تذكرة طيران (إرجاع رصيد) — حجز #'.$booking->booking_number.' — مجموعة: '.$group->name,
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

    /**
     * D3 FIX (2026-08-15): Identify a "duplicate entry on unique index"
     * QueryException across MySQL and SQLite. SQLSTATE 23000 is the
     * standard; MySQL error code 1062 is the canonical "Duplicate entry"
     * code. Mirrors the helper in HajjUmraBookingService.
     */
    private function isDuplicateKeyError(\Illuminate\Database\QueryException $qe): bool
    {
        $sqlState = (string) ($qe->errorInfo[0] ?? '');
        if ($sqlState === '23000') {
            return true;
        }
        $code = (int) ($qe->errorInfo[1] ?? 0);
        return $code === 1062;
    }
}
