<?php

namespace Tests\Feature\Flight\Support;

use App\Enums\AccountType;
use App\Enums\BookingChannelType;
use App\Enums\FlightBookingStatus;
use App\Enums\FlightPaymentMethod;
use App\Models\Account;
use App\Models\Customer;
use App\Models\Flight\FlightBooking;
use App\Models\Flight\FlightCarrier;
use App\Models\Flight\FlightGroup;
use App\Models\Flight\FlightPayment;
use App\Models\Flight\FlightSystem;
use App\Models\Flight\RefundRequest;
use App\Models\Setting\Currency;
use App\Models\Treasury;
use App\Models\User;
use App\Services\Flight\FlightBookingService;
use App\Services\Flight\FlightCarrierRechargeService;
use App\Services\Flight\RefundService;
use App\Support\Finance\LedgerBalanceMutationGuard;
use Illuminate\Support\Facades\DB;

/**
 * FlightAuditScenarioBuilder — fixture factory + scenario orchestrator.
 *
 * Builds an isolated per-currency world (carrier/system/group/cashbox)
 * and provides high-level helpers to drive booking lifecycle scenarios
 * end-to-end and verify accounting integrity at every step.
 *
 * Notes:
 * - All five currencies are seeded at setup(): EGP, USD, SAR, EUR, KWD.
 * - Each currency has: one FlightCarrier (SIGN), one FlightSystem (SYSTEM),
 *   one FlightGroup (GROUP), and one Cashbox (Account).
 * - Carriers/Systems are recharged from their respective cashboxes via
 *   FlightCarrierRechargeService so the booking pipeline can debit them.
 */
class FlightAuditScenarioBuilder
{
    /** Currency code → EGP-per-unit (matches seeded rates + FALLBACK) */
    public const RATES = [
        'EGP' => 1.0,
        'USD' => 49.50,
        'SAR' => 13.20,
        'EUR' => 53.80,
        'KWD' => 161.50,
    ];

    public AuditReporter $reporter;

    public User $admin;

    public Customer $customer;

    /** @var array<string, FlightCarrier> */
    public array $carriers = [];

    /** @var array<string, FlightSystem> */
    public array $systems = [];

    /** @var array<string, FlightGroup> */
    public array $groups = [];

    /** @var array<string, Account> */
    public array $cashboxes = [];

    /** @var array<string, Treasury> */
    public array $treasuries = [];

    public FlightBookingService $bookingService;

    public RefundService $refundService;

    public function __construct(AuditReporter $reporter)
    {
        $this->reporter = $reporter;
        $this->bookingService = app(FlightBookingService::class);
        $this->refundService = app(RefundService::class);
    }

    /**
     * The setUp() flow manually funds cashboxes via $cb->update(['balance' => X])
     * which bypasses the GL ledger. The resulting SUM(balance - entries) across
     * all accounts is a non-zero constant. This property tracks it so the final
     * reconciliation can verify the imbalance did not change during scenarios.
     */
    public float $baselineImbalance = 0.0;

    public function setup(): void
    {
        $this->ensureCurrencies();
        $this->ensureAdmin();
        $this->ensureCustomer();
        $this->ensurePerCurrencyFixtures();

        // Capture the initial (balance - entries) imbalance from manually-funded cashboxes.
        $this->baselineImbalance = (float) (DB::table('accounts')
            ->selectRaw('SUM(balance - (COALESCE((SELECT SUM(credit) FROM account_entries WHERE account_entries.account_id = accounts.id),0) - COALESCE((SELECT SUM(debit) FROM account_entries WHERE account_entries.account_id = accounts.id),0))) as delta')
            ->value('delta') ?? 0.0);
    }

    private function ensureCurrencies(): void
    {
        foreach (self::RATES as $code => $rate) {
            Currency::query()->updateOrCreate(
                ['code' => $code],
                [
                    'name_ar' => $code,
                    'name_en' => $code,
                    'symbol' => $code,
                    'exchange_rate' => $rate,
                    'is_active' => true,
                    'order' => array_search($code, array_keys(self::RATES)),
                ],
            );
        }
    }

    private function ensureAdmin(): void
    {
        $this->admin = User::query()->updateOrCreate(
            ['email' => 'audit-admin@safarakealayna.test'],
            [
                'name' => 'Flight Audit Admin',
                'password' => bcrypt('audit-password-not-used'),
                'role' => 'admin',
                'is_active' => true,
            ],
        );
    }

    private function ensureCustomer(): void
    {
        $this->customer = Customer::query()->updateOrCreate(
            ['national_id' => 'AUD-'.uniqid()],
            [
                'full_name' => 'Audit Customer',
                'phone' => '01000000000',
                'email' => 'audit-customer@test.local',
                'city' => 'Cairo',
            ],
        );
        // CustomerLedgerObserver creates the EGP AR account on save — refresh.
        $this->customer->refresh();
    }

    private function ensurePerCurrencyFixtures(): void
    {
        foreach (array_keys(self::RATES) as $currency) {
            $this->ensureCashbox($currency);
            $this->ensureTreasury($currency);
            $this->ensureCarrier($currency);
            $this->ensureSystem($currency);
            $this->ensureGroup($currency);
        }
    }

    private function ensureCashbox(string $currency): void
    {
        $name = "Audit Cashbox {$currency}";

        // The Account model has LedgerBalanceMutationGuard that blocks direct
        // balance writes. We wrap the whole setup in the guard so the test
        // fixture is allowed to set initial balances without going through the
        // full GL journal flow (which would require a contra account).
        LedgerBalanceMutationGuard::run(function () use ($name, $currency) {
            $cb = Account::query()->updateOrCreate(
                ['name' => $name, 'currency' => $currency],
                [
                    'type' => AccountType::Cashbox,
                    'balance' => 0,
                    'is_active' => true,
                    'owner_type' => 'office',
                    'module_type' => 'tourism',
                    'created_by' => $this->admin->id,
                ],
            );

            // Top up via direct balance assignment — only inside the guard.
            $initialBalance = match (strtoupper((string) $currency)) {
                'KWD' => 1000.0,
                'USD' => 50000.0,
                'EUR' => 50000.0,
                'SAR' => 20000.0,
                'EGP' => 500000.0,
                default => 100000.0,
            };
            if ((float) $cb->balance < $initialBalance) {
                $cb->update(['balance' => $initialBalance]);
            }

            $this->cashboxes[$currency] = $cb->fresh();
        });
    }

    private function ensureTreasury(string $currency): void
    {
        $name = "Audit Treasury {$currency}";
        $t = Treasury::query()->updateOrCreate(
            ['name' => $name],
            [
                'currency' => $currency,
                'is_active' => true,
                'current_balance' => 0,
                'created_by' => $this->admin->id,
            ],
        );
        $this->treasuries[$currency] = $t->fresh();
    }

    private function ensureCarrier(string $currency): void
    {
        $code = 'AUD-'.$currency;
        LedgerBalanceMutationGuard::run(function () use ($code, $currency) {
            $carrier = FlightCarrier::query()->updateOrCreate(
                ['code' => $code],
                [
                    'name' => "Audit Carrier {$currency}",
                    'flight_system_id' => null,
                    'iata_code' => substr($code, 0, 2),
                    'currency' => $currency,
                    'balance' => 0,
                    'credit_limit' => 999999999,
                    'is_active' => true,
                    'created_by' => $this->admin->id,
                ],
            );

            // Recharge the carrier via the canonical service.
            $rechargeAmount = match ($currency) {
                'KWD' => 500.0,
                'USD' => 2000.0,
                'EUR' => 2000.0,
                'SAR' => 10000.0,
                default => 200000.0,
            };

            try {
                app(FlightCarrierRechargeService::class)->rechargeFromAccount(
                    $carrier,
                    $this->cashboxes[$currency],
                    $rechargeAmount,
                    "Audit setup recharge ({$currency})",
                );
            } catch (\Throwable $e) {
                $this->reporter->info("Carrier {$currency} recharge skipped: ".$e->getMessage());
            }

            $this->carriers[$currency] = $carrier->fresh();
        });
    }

    private function ensureSystem(string $currency): void
    {
        $code = 'AUDS-'.$currency;
        $system = FlightSystem::query()->updateOrCreate(
            ['code' => $code],
            [
                'name' => "Audit System {$currency}",
                'type' => 'gds',
                'currency' => $currency,
                'balance' => 0,
                'credit_limit' => 999999999,
                'is_active' => true,
                'created_by' => $this->admin->id,
            ],
        );
        $this->systems[$currency] = $system->fresh();
    }

    private function ensureGroup(string $currency): void
    {
        $code = 'AUDG-'.$currency;
        // Each group needs an Account — reuse the cashbox if same currency.
        $account = $this->cashboxes[$currency];

        $group = FlightGroup::query()->updateOrCreate(
            ['code' => $code],
            [
                'name' => "Audit Group {$currency}",
                'flight_carrier_id' => null,
                'currency' => $currency,
                'account_id' => $account->id,
                'commission_rate' => 0,
                'credit_limit' => 999999999,
                'is_active' => true,
                'created_by' => $this->admin->id,
            ],
        );
        $this->groups[$currency] = $group->fresh();
    }

    /**
     * Build a signed-in context and a full-pay booking for the given channel+currency.
     * Returns [FlightBooking, snapshot].
     */
    public function createFullPaidBooking(string $channel, string $currency, array $overrides = []): array
    {
        auth()->login($this->admin);

        $rate = self::RATES[$currency];
        $purchaseForeign = 100.0; // 100 units of booking currency
        $purchaseEgp = round($purchaseForeign * $rate, 2);
        $sellingEgp = round($purchaseEgp * 1.10, 2); // 10% margin
        $sellingForeign = $currency === 'EGP' ? $sellingEgp : round($sellingEgp / $rate, 2);

        $data = [
            'customer_id' => $this->customer->id,
            'airline_name' => "Audit Air {$currency}",
            'airline' => "Audit Air {$currency}",
            'from_airport' => 'CAI',
            'to_airport' => 'JED',
            'departure_date' => now()->addDays(7)->toDateString(),
            'trip_type' => 'one_way',
            'currency' => $currency,
            'purchase_price_foreign' => $purchaseForeign,
            'purchase_price' => $purchaseEgp,
            'selling_price' => $sellingEgp,
            'passengers' => [
                ['first_name' => 'Audit', 'last_name' => 'Pax', 'type' => 'adult'],
            ],
            'pnr' => 'AUD-'.strtoupper(uniqid()),
        ];

        $data = array_merge($data, $this->channelPayload($channel, $currency));
        $data = array_merge($data, $overrides);

        $booking = $this->bookingService->createBooking($data);
        $booking->refresh();

        $before = $this->snapshotRelevant($booking);

        // Add a full payment so the booking reaches CONFIRMED.
        // The payment amount MUST be in the cashbox's currency (per addPayment rules).
        // For EGP bookings paid from EGP cashbox → amount is in EGP.
        // For USD bookings paid from USD cashbox → amount is in USD (foreign-currency equivalent).
        $paymentData = [
            'amount' => $sellingForeign,
            'payment_method' => FlightPaymentMethod::Cash->value,
            'account_id' => $this->cashboxes[$currency]->id,
        ];
        $this->bookingService->addPayment($booking, $paymentData);

        $booking->refresh();
        $after = $this->snapshotRelevant($booking);

        return [$booking, $before, $after];
    }

    public function channelPayload(string $channel, string $currency): array
    {
        return match ($channel) {
            'SIGN' => [
                'booking_channel_type' => BookingChannelType::SIGN->value,
                'booking_channel_provider' => 'SIGN',
                'flight_carrier_id' => $this->carriers[$currency]->id,
                'purchase_balance_source' => 'carrier',
            ],
            'SYSTEM' => [
                'booking_channel_type' => BookingChannelType::SYSTEM->value,
                'booking_channel_provider' => 'Amadeus',
                'flight_system_id' => $this->systems[$currency]->id,
                'purchase_balance_source' => 'system',
            ],
            'GROUP' => [
                'booking_channel_type' => BookingChannelType::GROUP->value,
                'booking_channel_provider' => 'Voyage',
                'flight_group_id' => $this->groups[$currency]->id,
                'purchase_balance_source' => 'group',
            ],
            default => throw new \InvalidArgumentException("Unknown channel: {$channel}"),
        };
    }

    public function cancelWithPenalty(FlightBooking $booking, float $airlinePenalty = 0, float $officePenalty = 0): array
    {
        $before = $this->snapshotRelevant($booking);

        $this->bookingService->cancelBooking($booking->refresh(), [
            'airline_penalty' => $airlinePenalty,
            'office_penalty' => $officePenalty,
            'account_id' => $this->cashboxes[$booking->currency]->id,
            'notes' => 'Audit cancel',
        ]);

        $booking->refresh();
        $after = $this->snapshotRelevant($booking);

        return [$before, $after];
    }

    public function deleteBooking(FlightBooking $booking): array
    {
        $bookingId = $booking->id;
        $before = $this->snapshotRelevant($booking);

        $this->bookingService->deleteBookingWithReversal($bookingId, $this->admin->id);

        // Re-load (with trashed) for inspection.
        $booking = FlightBooking::withTrashed()->find($bookingId);
        $after = $this->snapshotRelevant($booking, withTrashed: true);

        return [$before, $after, $booking];
    }

    public function snapshotRelevant(?FlightBooking $booking, bool $withTrashed = false): array
    {
        $cashboxKey = $booking ? strtoupper((string) $booking->currency) : 'EGP';
        $cashbox = $this->cashboxes[$cashboxKey] ?? $this->cashboxes['EGP'];

        $cashboxBal = (float) $cashbox->fresh()->balance;

        $carrierBal = 0.0;
        if ($booking && $booking->flight_carrier_id) {
            $carrierBal = (float) FlightCarrier::find($booking->flight_carrier_id)?->balance ?? 0;
        }
        $systemBal = 0.0;
        if ($booking && $booking->flight_system_id) {
            $systemBal = (float) FlightSystem::find($booking->flight_system_id)?->balance ?? 0;
        }

        $customerAccountId = $this->customer->fresh()->account_id;
        $customerBal = $customerAccountId ? (float) Account::find($customerAccountId)?->balance ?? 0 : 0;

        $pendingSales = 0.0;
        $incomeClearing = 0.0;
        $pendingId = app(\App\Services\Finance\LedgerClearingAccounts::class)->pendingSalesReceivableIdForFlight();
        if ($pendingId) {
            $pendingSales = (float) Account::find($pendingId)?->balance ?? 0;
        }
        $clearingId = app(\App\Services\Finance\LedgerClearingAccounts::class)->incomeContraIdForFlightBooking();
        if ($clearingId) {
            $incomeClearing = (float) Account::find($clearingId)?->balance ?? 0;
        }

        $orphanEntries = (int) DB::table('account_entries')
            ->whereNotIn('transaction_id', DB::table('transactions')->select('id'))
            ->count();

        // Count transactions whose entries don't balance (debit != credit by more than 0.01).
        // Cross-currency aware: a transaction with entries in DIFFERENT currencies is a
        // legitimate cross-currency transfer (debit USD, credit EGP-equivalent at FX rate).
        // Only count as unbalanced if entries within the SAME currency don't sum to zero.
        $unbalancedDetails = DB::table('account_entries as ae')
            ->join('accounts as a', 'ae.account_id', '=', 'a.id')
            ->select(
                'ae.transaction_id',
                DB::raw('a.currency as currency'),
                DB::raw('SUM(ae.debit) as d'),
                DB::raw('SUM(ae.credit) as c'),
                DB::raw('ABS(SUM(ae.debit) - SUM(ae.credit)) as diff'),
                DB::raw('COUNT(*) as entry_count'),
            )
            ->groupBy('ae.transaction_id', 'a.currency')
            ->havingRaw('ABS(SUM(ae.debit) - SUM(ae.credit)) > 0.01')
            ->orderBy('ae.transaction_id')
            ->get()
            ->groupBy('transaction_id')
            ->filter(function ($group) {
                // Only count as unbalanced if it has entries in a SINGLE currency that don't balance.
                // Multi-currency entries are cross-currency transfers (legitimately unbalanced in raw numbers).
                return $group->count() === 1;
            })
            ->flatten();
        $unbalancedTx = $unbalancedDetails->count();

        // We can't check absolute account.balance vs entries because the test setup
        // manually funds cashboxes (raw $cb->update(['balance' => X])), which bypasses
        // the GL ledger. Instead we compute the SUM of (balance - (credit-debit)) across
        // all accounts — this is the test fixture "imbalance". If this number is stable
        // across two snapshots, then no operation introduced new imbalance.
        $accountBalanceMismatch = (float) DB::table('accounts')
            ->selectRaw('SUM(balance - (COALESCE((SELECT SUM(credit) FROM account_entries WHERE account_entries.account_id = accounts.id),0) - COALESCE((SELECT SUM(debit) FROM account_entries WHERE account_entries.account_id = accounts.id),0))) as delta')
            ->value('delta') ?? 0.0;

        return [
            'cashbox' => $cashboxBal,
            'carrier' => $carrierBal,
            'system' => $systemBal,
            'customer' => $customerBal,
            'pending_sales_receivable' => $pendingSales,
            'income_clearing' => $incomeClearing,
            'orphan_entries' => $orphanEntries,
            'unbalanced_transactions' => $unbalancedTx,
            'unbalanced_details' => $unbalancedDetails->toArray(),
            'account_balance_mismatch' => $accountBalanceMismatch,
            'booking' => $booking,
        ];
    }

    public function pnl(): array
    {
        $report = app(\App\Services\Reports\ProfitLossReportService::class)->report([]);

        return [
            'totalRevenues' => (float) ($report['totalRevenues'] ?? 0),
            'totalCogs' => (float) ($report['totalCogs'] ?? 0),
            'grossProfit' => (float) ($report['grossProfit'] ?? 0),
            'netProfit' => (float) ($report['netProfit'] ?? 0),
        ];
    }

    public function delta(array $before, array $after, array $keys): array
    {
        $out = [];
        foreach ($keys as $k) {
            $out[$k] = round(($after[$k] ?? 0) - ($before[$k] ?? 0), 2);
        }

        return $out;
    }
}
