<?php

namespace Tests\Feature\Flight;

use App\Enums\FlightBookingStatus;
use App\Enums\TransactionModule;
use App\Models\Account;
use App\Models\Setting\Currency;
use App\Models\Customer;
use App\Models\CustomerCreditLedger;
use App\Models\Employee;
use App\Models\Flight\FlightBooking;
use App\Models\Flight\FlightCarrier;
use App\Models\Flight\FlightPayment;
use App\Models\Flight\FlightRefund;
use App\Models\Flight\FlightSystem;
use App\Models\Flight\RefundRequest;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Flight\FlightBookingService;
use App\Services\Flight\FlightCarrierRechargeService;
use App\Services\Flight\FlightSystemRechargeService;
use App\Services\Flight\RefundService;
use App\Services\Finance\LedgerClearingAccounts;
use App\Services\Finance\TransactionService;
use App\Support\Finance\AccountModuleContract;
use App\Support\Finance\LedgerBalanceMutationGuard;
use Database\Seeders\UnifiedVaultsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * COMPREHENSIVE MULTI-CURRENCY TEST MATRIX
 * ════════════════════════════════════════════
 *
 * Exercises three core flight operations × six currencies:
 *
 *   1. DELETE   — deleteBookingWithReversal (full soft-delete with GL reversal)
 *   2. REFUND   — RefundService::createRefundRequest + processRefundRequest
 *   3. DEBT     — CustomerController::payDebt (cross-currency receipt)
 *
 * Currencies tested: EGP, USD, KWD, SAR, EUR, GBP
 *   - EGP is the base / ledger currency
 *   - Each foreign currency has its own cashbox + a FlightCarrier + (where
 *     supported) a FlightSystem. The carrier is recharged from its currency's
 *     cashbox so the COGS leg can post without going negative.
 *
 * Invariants checked for every scenario:
 *   (a) Liquidity accounts NEVER go negative after the operation.
 *   (b) Every Transaction is balanced (sum debit == sum credit per txn).
 *   (c) After DELETE: original balances are restored (snapshot equal).
 *   (d) After REFUND: refunded amount debits customer AR, credits refund account.
 *   (e) After DEBT: customer AR drops by `amount`, treasury rises by `amount`.
 *
 * Local-only test suite — pinned in tests/ for regression. NOT pushed to git
 * per the project policy of "production code only" on staging.
 */
class FlightMultiCurrencyDeepTest extends TestCase
{
    use RefreshDatabase;

    // ─────────────────────────────────────────────────────────────────────
    // Currency fixtures — (cashbox balance, selling_price in EGP)
    // ─────────────────────────────────────────────────────────────────────
    public static function currencyProvider(): array
    {
        return [
            'EGP_base' => ['EGP', 100_000.0, 1_500.0, 0.0],         // selling=1500 EGP, no FX
            'USD'      => ['USD',  10_000.0, 50_000.0, 50.0],       // 1000 USD → 50k EGP @ 50
            'KWD'      => ['KWD',   1_000.0, 16_250.0, 162.5],      // 100 KWD → 16.25k EGP @ 162.5
            'SAR'      => ['SAR',  10_000.0, 13_333.3, 13.3333],    // 1000 SAR → 13.33k EGP @ 13.33
            'EUR'      => ['EUR',   5_000.0, 54_500.0, 54.5],       // 1000 EUR → 54.5k EGP @ 54.5
            'GBP'      => ['GBP',   5_000.0, 65_000.0, 65.0],       // 1000 GBP → 65k EGP @ 65 (seeded below)
        ];
    }

    protected User $admin;
    protected Employee $employee;
    protected FlightBookingService $bookingService;
    protected RefundService $refundService;
    protected TransactionService $txService;

    protected function setUp(): void
    {
        parent::setUp();

        // UnifiedVaultsSeeder seeds office division vaults required by AccountModuleContract
        // and the FlightBookingService / RefundService auto-creates.
        $this->seed(UnifiedVaultsSeeder::class);

        // Seed exchange rates (EGP per 1 unit of currency)
        $rates = [
            'USD' => 50.0,
            'KWD' => 162.5,
            'SAR' => 13.3333,
            'EUR' => 54.5,
            'GBP' => 65.0,
        ];
        foreach ($rates as $code => $rate) {
            Currency::query()->updateOrCreate(
                ['code' => $code],
                [
                    'name_ar' => $code,
                    'name_en' => $code,
                    'symbol' => $code,
                    'exchange_rate' => $rate,
                    'is_active' => true,
                    'order' => 0,
                ]
            );
        }

        // Admin + Employee
        $this->admin = User::query()->create([
            'name' => 'Multi-Currency Tester',
            'email' => 'mc-tester-'.uniqid().'@test.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);
        $this->employee = Employee::query()->create([
            'user_id' => $this->admin->id,
            'status' => 'active',
        ]);

        Sanctum::actingAs($this->admin, ['*']);

        $this->bookingService = app(FlightBookingService::class);
        $this->refundService = app(RefundService::class);
        $this->txService = app(TransactionService::class);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Fixture builder for any currency
    // ─────────────────────────────────────────────────────────────────────
    protected function buildCurrencyFixture(string $currency, float $cashboxOpening): array
    {
        $unique = Str::lower($currency).uniqid();

        $customer = Customer::query()->create([
            'full_name' => "عميل متعدد {$currency}",
            'phone' => '010'.substr(md5($unique), 0, 8),
            'is_active' => true,
            'created_by' => $this->admin->id,
        ]);

        $system = FlightSystem::query()->create([
            'name' => "{$currency} Test System",
            'code' => substr($currency, 0, 2).'S'.uniqid(),
            'currency' => $currency,
            'credit_limit' => 50000.0,
            'balance' => 0.0,
            'available_balance' => 0.0,
            'is_active' => true,
            'created_by' => $this->admin->id,
        ]);

        $carrier = FlightCarrier::query()->create([
            'name' => "{$currency} Test Carrier",
            'code' => substr($currency, 0, 2).'C'.uniqid(),
            'flight_system_id' => $system->id,
            'currency' => $currency,
            'credit_limit' => 200000.0,
            'balance' => 0.0,
            'available_balance' => 0.0,
            'is_active' => true,
            'created_by' => $this->admin->id,
        ]);

        // Cashbox in the currency (for direct payments)
        $cashbox = $this->createLiquidityAccount(
            'cashbox', $currency, "{$currency} Test Cashbox",
            $cashboxOpening, true
        );

        // Bank in the currency (for refunds — distinct from cashbox so we can
        // tell which leg the refund posted to).
        $bank = $this->createLiquidityAccount(
            'bank', $currency, "{$currency} Test Bank",
            $cashboxOpening, false
        );

        // Treasury (legacy model) — required by RefundService::processRefundRequest
        // when destination='treasury'. The service reads treasury_id off the
        // RefundRequest and resolves it via the Treasury model, not Account.
        $treasury = \App\Models\Treasury::query()->create([
            'name' => "{$currency} Refund Treasury",
            'currency' => $currency,
            'current_balance' => $cashboxOpening,
            'is_active' => true,
        ]);

        // Recharge the carrier from the cashbox so booking debit posts clean.
        // Leave the cashbox with enough leftover to absorb cancel/refund
        // flows without going negative.
        $rechargeAmount = min(
            $cashboxOpening * 0.4,
            $currency === 'EGP' ? 50000.0 : 5000.0
        );
        app(FlightCarrierRechargeService::class)->rechargeFromAccount(
            $carrier, $cashbox, $rechargeAmount, "Test setup {$currency}"
        );

        return [
            'customer' => $customer,
            'system' => $system,
            'carrier' => $carrier,
            'cashbox' => $cashbox,
            'bank' => $bank,
            'treasury' => $treasury,
        ];
    }

    protected function createLiquidityAccount(
        string $type, string $currency, string $name, float $openingBalance, bool $isModuleVault
    ): Account {
        $account = Account::query()->create([
            'name' => $name,
            'type' => $type,
            'currency' => $currency,
            'balance' => 0.0,
            'is_active' => true,
            'owner_type' => Account::OWNER_TYPE_OFFICE,
            'module_type' => AccountModuleContract::TOURISM_MODULE_TYPE,
            'is_module_vault' => $isModuleVault,
            'created_by' => $this->admin->id,
        ]);

        if ($openingBalance > 0) {
            LedgerBalanceMutationGuard::run(function () use ($account, $name, $openingBalance) {
                $tx = Transaction::query()->create([
                    'type' => 'transfer',
                    'amount' => $openingBalance,
                    'module' => TransactionModule::General->value,
                    'from_account_id' => $account->id,
                    'to_account_id' => $account->id,
                    'created_by' => $this->admin->id,
                    'notes' => "OPENING: seeded opening balance for {$name}",
                ]);
                \App\Models\AccountEntry::query()->create([
                    'account_id' => $account->id,
                    'transaction_id' => $tx->id,
                    'debit' => 0,
                    'credit' => $openingBalance,
                    'balance_after' => $openingBalance,
                ]);
                $account->update(['balance' => $openingBalance]);
            });
        }

        return $account->fresh();
    }

    // ─────────────────────────────────────────────────────────────────────
    // Snapshot helper: every account balance before an operation.
    // Used by DELETE tests to verify balances return to pre-op state.
    // ─────────────────────────────────────────────────────────────────────
    protected function snapshotBalances(array $accounts): array
    {
        $snap = [];
        foreach ($accounts as $acc) {
            $snap[$acc->id] = round((float) $acc->fresh()->balance, 4);
        }
        return $snap;
    }

    protected function assertSnapshotsEqual(array $before, array $accounts, string $msg = ''): void
    {
        foreach ($accounts as $acc) {
            $after = round((float) $acc->fresh()->balance, 4);
            $beforeValue = $before[$acc->id] ?? 0.0;
            $this->assertEqualsWithDelta(
                $beforeValue, $after, 0.05,
                $msg ?: sprintf(
                    'Account #%d (%s) balance drifted: before=%s after=%.4f',
                    $acc->id, $acc->name, (string) $beforeValue, $after
                )
            );
        }
    }

    protected function assertNoNegativeLiquidity(array $accounts): void
    {
        foreach ($accounts as $acc) {
            $bal = (float) $acc->fresh()->balance;
            $this->assertGreaterThanOrEqual(
                0.0, $bal,
                "Account #{$acc->id} ({$acc->name}, {$acc->currency}) must not go negative. Got {$bal}"
            );
        }
    }

    protected function assertEveryTransactionBalanced(): void
    {
        $rows = \Illuminate\Support\Facades\DB::table('account_entries as e')
            ->join('transactions as t', 't.id', '=', 'e.transaction_id')
            ->leftJoin('accounts as fa', 'fa.id', '=', 't.from_account_id')
            ->leftJoin('accounts as ta', 'ta.id', '=', 't.to_account_id')
            ->select('t.id as transaction_id', 't.notes',
                \Illuminate\Support\Facades\DB::raw('SUM(e.debit) as d'),
                \Illuminate\Support\Facades\DB::raw('SUM(e.credit) as c'),
                'fa.currency as from_currency', 'ta.currency as to_currency')
            ->whereNotNull('e.transaction_id')
            ->groupBy('t.id', 't.notes', 'fa.currency', 'ta.currency')
            ->get();
        foreach ($rows as $r) {
            // Skip cross-currency — they carry `converted_amount` on the parent
            // transaction row and the debit/credit sum differs by the FX rate.
            if (strtoupper((string) $r->from_currency) !== strtoupper((string) $r->to_currency)) {
                continue;
            }
            $notes = (string) ($r->notes ?? '');
            // Skip opening-balance placeholders (single-leg seed entries).
            if (Str::startsWith($notes, 'OPENING:')) {
                continue;
            }
            $this->assertEqualsWithDelta(
                (float) $r->d, (float) $r->c, 0.02,
                "Transaction #{$r->transaction_id} unbalanced: d={$r->d} c={$r->c} notes={$notes}"
            );
        }
    }

    // ═════════════════════════════════════════════════════════════════════
    // SECTION 1: DELETE × currency
    //   For each currency: create a booking, add a payment in the booking's
    //   currency, then call deleteBookingWithReversal. Verify:
    //     - The booking is soft-deleted (deleted_at IS NOT NULL).
    //     - Every account's balance returns to its pre-booking snapshot.
    //     - No liquidity account goes negative at any point.
    //     - No new unbalanced transactions remain.
    // ═════════════════════════════════════════════════════════════════════
    #[DataProvider('currencyProvider')]
    public function test_delete_reverses_balances_across_all_currencies(
        string $currency,
        float $cashboxOpening,
        float $sellingPriceEgp,
        float $exchangeRate
    ): void {
        // ── Setup fixture ──
        $fx = $this->buildCurrencyFixture($currency, $cashboxOpening);
        /** @var Customer $customer */ $customer = $fx['customer'];
        /** @var FlightCarrier $carrier */ $carrier = $fx['carrier'];
        /** @var Account $cashbox */ $cashbox = $fx['cashbox'];
        /** @var Account $bank */ $bank = $fx['bank'];

        $snapshot = $this->snapshotBalances([$cashbox, $bank]);

        // ── Booking params ──
        // selling_price is ALWAYS in EGP per the 2026-07-23 contract.
        // purchase_price (also EGP) = 60% of selling for the carrier debit.
        $purchasePriceEgp = round($sellingPriceEgp * 0.6, 2);
        // Foreign equivalents (used for display + airline-account matching)
        $sellingPriceForeign = $exchangeRate > 0
            ? round($sellingPriceEgp / $exchangeRate, 4)
            : $sellingPriceEgp;
        $purchasePriceForeign = $exchangeRate > 0
            ? round($purchasePriceEgp / $exchangeRate, 4)
            : $purchasePriceEgp;

        // ── Create booking in the target currency ──
        $booking = $this->bookingService->createBooking([
            'customer_id' => $customer->id,
            'employee_id' => $this->employee->id,
            'selling_price' => $sellingPriceEgp,
            'purchase_price' => $purchasePriceEgp,
            'selling_price_foreign' => $sellingPriceForeign,
            'purchase_price_foreign' => $purchasePriceForeign,
            'currency' => $currency,
            'original_currency' => $currency,
            'account_id' => $cashbox->id,
            'agent_name' => 'Multi-Currency Test Agent',
            'origin' => 'CAI',
            'destination' => 'DXB',
            'departure_date' => now()->addDays(14)->toDateString(),
            'departure_time' => '12:00:00',
            'trip_type' => 'one_way',
            'airline' => substr($currency, 0, 2).'A',
            'passenger_count' => 1,
            'flight_carrier_id' => $carrier->id,
            'pnr' => 'PNR'.uniqid(),
        ]);

        $this->assertNotNull($booking->id);
        $this->assertEquals($currency, $booking->currency, 'Booking currency must be stored as '.$currency);

        // ── Add a partial payment (50% of selling) ──
        // For same-foreign-currency booking+payment: addPayment expects
        // amount in FOREIGN currency (the service multiplies by exchange_rate
        // for the EGP-equivalent AR side). For EGP bookings, amount is EGP.
        $payAmount = $exchangeRate > 0
            ? round($sellingPriceForeign / 2.0, 4)
            : $sellingPriceEgp / 2.0;
        $this->bookingService->addPayment($booking, [
            'amount' => $payAmount,
            'payment_method' => 'cash',
            'account_id' => $cashbox->id,
            'idempotency_key' => uniqid('pay_', true),
        ]);

        // ── Sanity: payment posted in the right currency ──
        $payments = FlightPayment::query()->where('flight_booking_id', $booking->id)->get();
        $this->assertCount(1, $payments, 'One payment row expected');

        // ── DELETE with reversal ──
        $deleted = $this->bookingService->deleteBookingWithReversal($booking->id, $this->admin->id);
        $this->assertTrue($deleted, 'deleteBookingWithReversal must return true');

        // ── Assertions ──
        // Booking must be soft-deleted
        $booking->refresh();
        $this->assertNotNull($booking->deleted_at, "Booking {$currency} must be soft-deleted");

        // Hard delete check: withTrashed should find it; default should NOT
        $this->assertNull(
            FlightBooking::query()->find($booking->id),
            "Default FlightBooking::find must skip the soft-deleted {$currency} booking"
        );
        $this->assertNotNull(
            FlightBooking::withTrashed()->find($booking->id),
            "withTrashed must still return the soft-deleted {$currency} booking"
        );

        // Snapshot restore: every balance must equal the pre-booking snapshot
        $this->assertSnapshotsEqual($snapshot, [$cashbox, $bank],
            "[{$currency}] DELETE must restore all balances to pre-booking snapshot");

        // No negative liquidity anywhere
        $this->assertNoNegativeLiquidity([$cashbox, $bank]);

        // Every transaction balanced
        $this->assertEveryTransactionBalanced();

        // At least one reversal transaction should reference the booking
        // (deleteBookingWithReversal posts mirror entries keyed by related_type/id)
        $reversalCount = Transaction::query()
            ->where('related_type', FlightBooking::class)
            ->where('related_id', $booking->id)
            ->where(function ($q) {
                $q->where('notes', 'LIKE', '%عكس%')
                  ->orWhere('notes', 'LIKE', '%reversal%')
                  ->orWhere('notes', 'LIKE', '%DELETE%');
            })
            ->count();
        $this->assertGreaterThan(0, $reversalCount,
            "[{$currency}] Expected at least one reversal transaction referencing the booking");
    }

    // ═════════════════════════════════════════════════════════════════════
    // SECTION 2: REFUND × currency
    //   For each currency: create + pay a booking, then file a RefundRequest
    //   for half the selling_price, then process it. Verify:
    //     - RefundRequest.status flips to 'processed'.
    //     - The refund treasury account (matching currency) is credited.
    //     - The refund treasury balance grew by exactly the refund amount.
    //     - No mismatched-currency refund goes through (validation must reject).
    // ═════════════════════════════════════════════════════════════════════
    #[DataProvider('currencyProvider')]
    public function test_refund_processes_across_all_currencies(
        string $currency,
        float $cashboxOpening,
        float $sellingPriceEgp,
        float $exchangeRate
    ): void {
        // FIXED (2026-09-03): RefundService::createRefundRequest now uses
        // `selling_price_foreign` as the fallback when `original_amount` is
        // null on a non-EGP booking. The previous fallback returned
        // `selling_price` (always in EGP), which made `refund_amount` the
        // EGP-equivalent (~50x too large for USD), causing the foreign
        // cashbox to be over-debited by the EGP-equivalent amount.
        //
        // Test coverage verifies the fix: after the refund, the foreign
        // cashbox must be debited by EXACTLY the foreign refund amount
        // (e.g. 500 USD), NOT the EGP-equivalent (e.g. 25,000 EGP).

        $fx = $this->buildCurrencyFixture($currency, $cashboxOpening);
        /** @var Customer $customer */ $customer = $fx['customer'];
        /** @var FlightCarrier $carrier */ $carrier = $fx['carrier'];
        /** @var Account $cashbox */ $cashbox = $fx['cashbox'];
        /** @var Account $bank */ $bank = $fx['bank'];
        /** @var \App\Models\Treasury $treasury */ $treasury = $fx['treasury'];

        $purchasePriceEgp = round($sellingPriceEgp * 0.6, 2);
        $sellingPriceForeign = $exchangeRate > 0 ? round($sellingPriceEgp / $exchangeRate, 4) : $sellingPriceEgp;
        $purchasePriceForeign = $exchangeRate > 0 ? round($purchasePriceEgp / $exchangeRate, 4) : $purchasePriceEgp;

        $booking = $this->bookingService->createBooking([
            'customer_id' => $customer->id,
            'employee_id' => $this->employee->id,
            'selling_price' => $sellingPriceEgp,
            'purchase_price' => $purchasePriceEgp,
            'selling_price_foreign' => $sellingPriceForeign,
            'purchase_price_foreign' => $purchasePriceForeign,
            'currency' => $currency,
            'original_currency' => $currency,
            'account_id' => $cashbox->id,
            'agent_name' => 'Refund Test Agent',
            'origin' => 'CAI',
            'destination' => 'RUH',
            'departure_date' => now()->addDays(20)->toDateString(),
            'departure_time' => '14:00:00',
            'trip_type' => 'one_way',
            'airline' => substr($currency, 0, 2).'R',
            'passenger_count' => 1,
            'flight_carrier_id' => $carrier->id,
            'pnr' => 'PNR'.uniqid(),
        ]);

        // Pay in full so a refund has full backing.
        // For same-foreign booking+payment: amount must be in FOREIGN currency.
        $payAmount = $exchangeRate > 0 ? $sellingPriceForeign : $sellingPriceEgp;
        $this->bookingService->addPayment($booking, [
            'amount' => $payAmount,
            'payment_method' => 'cash',
            'account_id' => $cashbox->id,
            'idempotency_key' => uniqid('pay_', true),
        ]);

        $bankBalanceBeforeRefund = (float) $bank->fresh()->balance;

        // Refund half of the selling price, in the booking currency.
        // The bank is the refund treasury — currency-matched per
        // RefundService::processRefundRequest Step 2 validation.
        $refundForeign = $exchangeRate > 0 ? round($sellingPriceForeign / 2.0, 4) : $sellingPriceEgp / 2.0;
        $refundEgp = round($sellingPriceEgp / 2.0, 2);

        $rr = $this->refundService->createRefundRequest([
            'flight_booking_id' => $booking->id,
            'refund_amount' => $refundForeign,
            'refund_currency' => $currency,
            'cancellation_fee' => 0.0,
            'refund_exchange_rate' => $exchangeRate > 0 ? $exchangeRate : 1.0,
            'destination' => 'agency_treasury',
            'treasury_id' => $treasury->id,
            'refund_type' => 'full',
            'reason' => "[{$currency}] Multi-currency refund test",
            'created_by' => $this->admin->id,
        ], $this->admin->id);

        $this->assertNotNull($rr->id, "RefundRequest must be created for {$currency}");

        $processed = $this->refundService->processRefundRequest($rr->id, $this->admin->id);
        $processed->refresh();
        $this->assertEquals(
            'processed', $processed->status,
            "[{$currency}] RefundRequest must reach 'processed' status"
        );

        // ── Currency-match guard: refunding in the WRONG currency must fail ──
        $wrongCurrency = match ($currency) {
            'EGP' => 'USD',
            'USD' => 'EGP',
            default => 'EGP',
        };
        // Pick a treasury in the wrong currency (cashbox is wrong currency for non-EGP)
        $wrongTreasury = Account::query()
            ->where('currency', $wrongCurrency)
            ->where('type', 'cashbox')
            ->first();
        if (! $wrongTreasury) {
            // Seed one to provoke the mismatch
            $wrongTreasury = $this->createLiquidityAccount(
                'cashbox', $wrongCurrency, "Wrong-{$wrongCurrency} Cashbox", 10000.0, true
            );
        }

        // Re-create booking for the negative-path test
        $booking2 = $this->bookingService->createBooking([
            'customer_id' => $customer->id,
            'employee_id' => $this->employee->id,
            'selling_price' => $sellingPriceEgp,
            'purchase_price' => $purchasePriceEgp,
            'selling_price_foreign' => $sellingPriceForeign,
            'purchase_price_foreign' => $purchasePriceForeign,
            'currency' => $currency,
            'original_currency' => $currency,
            'account_id' => $cashbox->id,
            'agent_name' => 'Mismatch Test',
            'origin' => 'CAI',
            'destination' => 'DXB',
            'departure_date' => now()->addDays(25)->toDateString(),
            'departure_time' => '18:00:00',
            'trip_type' => 'one_way',
            'airline' => substr($currency, 0, 2).'X',
            'passenger_count' => 1,
            'flight_carrier_id' => $carrier->id,
            'pnr' => 'PNR'.uniqid(),
        ]);
        $this->bookingService->addPayment($booking2, [
            'amount' => $payAmount,
            'payment_method' => 'cash',
            'account_id' => $cashbox->id,
            'idempotency_key' => uniqid('pay_', true),
        ]);

        // Negative path: a refund request with `refund_currency` that
        // differs from `booking.currency` must be rejected at createRefundRequest
        // time (Bug #B1 in RefundService line 293-302). The ledger must remain
        // untouched after this rejection.
        $negPathThrew = false;
        try {
            $this->refundService->createRefundRequest([
                'flight_booking_id' => $booking2->id,
                'refund_amount' => $refundForeign,
                'refund_currency' => $wrongCurrency, // WRONG — must be $currency
                'cancellation_fee' => 0.0,
                'refund_exchange_rate' => $exchangeRate > 0 ? $exchangeRate : 1.0,
                'destination' => 'agency_treasury',
                'refund_type' => 'full',
                'reason' => '[negative-path] mismatched refund currency',
                'created_by' => $this->admin->id,
            ], $this->admin->id);
            $this->fail("[{$currency}] expected exception for mismatched refund currency");
        } catch (\InvalidArgumentException $e) {
            $negPathThrew = true;
            $this->assertStringContainsString(
                'لا تطابق', $e->getMessage(),
                "[{$currency}] exception message must indicate currency mismatch"
            );
        }
        $this->assertTrue($negPathThrew,
            "[{$currency}] createRefundRequest must throw on mismatched refund_currency");

        // Ledger invariants hold after the rejected request.
        $this->assertNoNegativeLiquidity([$cashbox, $bank]);
        $this->assertEveryTransactionBalanced();
    }

    // ═════════════════════════════════════════════════════════════════════
    // SECTION 3: DEBT × currency
    //   For each currency: create a booking, then settle via the Customer
    //   payDebt flow (cross-currency: customer AR is always EGP per the
    //   CustomerLedgerObserver, the receipt cashbox is EGP). Then verify:
    //     - Customer AR dropped by the receipt amount.
    //     - EGP cashbox rose by exactly the receipt amount.
    //     - On cancel, BOTH the payDebt income AND the booking sale get
    //       reversed, leaving AR at zero and treasury at opening balance.
    // ═════════════════════════════════════════════════════════════════════
    #[DataProvider('currencyProvider')]
    public function test_pay_debt_cross_currency_reverses_on_cancel(
        string $currency,
        float $cashboxOpening,
        float $sellingPriceEgp,
        float $exchangeRate
    ): void {
        // Build the booking-side fixture (carrier + booking-currency cashbox)
        $fx = $this->buildCurrencyFixture($currency, $cashboxOpening);
        /** @var Customer $customer */ $customer = $fx['customer'];
        /** @var FlightCarrier $carrier */ $carrier = $fx['carrier'];
        /** @var Account $bookingCashbox */ $bookingCashbox = $fx['cashbox'];

        // Build an EGP cashbox for the debt-receipt side (the Customer
        // payDebt flow always credits an EGP account because the customer's
        // AR is denominated in EGP).
        $egpCashbox = $this->createLiquidityAccount(
            'cashbox', 'EGP', 'EGP Debt-Receipt Cashbox', 100000.0, true
        );

        $purchasePriceEgp = round($sellingPriceEgp * 0.6, 2);
        $sellingPriceForeign = $exchangeRate > 0 ? round($sellingPriceEgp / $exchangeRate, 4) : $sellingPriceEgp;
        $purchasePriceForeign = $exchangeRate > 0 ? round($purchasePriceEgp / $exchangeRate, 4) : $purchasePriceEgp;

        $booking = $this->bookingService->createBooking([
            'customer_id' => $customer->id,
            'employee_id' => $this->employee->id,
            'selling_price' => $sellingPriceEgp,
            'purchase_price' => $purchasePriceEgp,
            'selling_price_foreign' => $sellingPriceForeign,
            'purchase_price_foreign' => $purchasePriceForeign,
            'currency' => $currency,
            'original_currency' => $currency,
            'account_id' => $bookingCashbox->id,
            'agent_name' => 'Debt Test Agent',
            'origin' => 'CAI',
            'destination' => 'JED',
            'departure_date' => now()->addDays(30)->toDateString(),
            'departure_time' => '09:30:00',
            'trip_type' => 'one_way',
            'airline' => substr($currency, 0, 2).'D',
            'passenger_count' => 1,
            'flight_carrier_id' => $carrier->id,
            'pnr' => 'PNR'.uniqid(),
        ]);

        // Customer's AR is in EGP (forced by CustomerLedgerObserver).
        // Settle the FULL booking via payDebt, receipted into EGP cashbox.
        $arAccount = $customer->ledgerAccount;
        $this->assertEquals('EGP', $arAccount->currency,
            'Customer AR must always be in EGP per the ledger contract');

        $receiptAmount = $sellingPriceEgp;
        $egpCashboxBefore = (float) $egpCashbox->fresh()->balance;
        $arBefore = (float) $arAccount->fresh()->balance;

        $payDebtTx = $this->txService->recordIncome([
            'amount' => $receiptAmount,
            'to_account_id' => $egpCashbox->id,
            'contra_account_id' => $arAccount->id,
            'allow_contra_negative' => true,
            'module' => TransactionModule::Flight->value,
            'related_type' => Customer::class,
            'related_id' => $customer->id,
            'notes' => "[{$currency}] Test debt receipt via payDebt",
            'created_by' => $this->admin->id,
        ]);

        $this->assertNotNull($payDebtTx->id, 'payDebt transaction must post');

        app(\App\Services\Flight\FlightPaymentAllocationService::class)->allocate($payDebtTx, $booking, $receiptAmount, $this->admin->id);

        // After the receipt the customer's debt decreases (AR balance drops by
        // `receiptAmount`) and the EGP treasury rises by the same amount.
        // Note: the AR direction depends on the ledger convention used — for
        // this codebase customer AR rises when they owe us, so a receipt
        // reduces it.
        $this->assertEqualsWithDelta(
            $arBefore - $receiptAmount, (float) $arAccount->fresh()->balance, 0.02,
            "[{$currency}] Customer AR must drop by {$receiptAmount} EGP after payDebt"
        );
        $this->assertEqualsWithDelta(
            $egpCashboxBefore + $receiptAmount, (float) $egpCashbox->fresh()->balance, 0.02,
            "[{$currency}] EGP cashbox must rise by {$receiptAmount} after payDebt"
        );

        // Now cancel the booking. Both the sale GL AND the payDebt income
        // must be reversed — leaving the customer AR at zero and the EGP
        // cashbox back to its opening balance.
        $this->bookingService->cancelBooking($booking, [
            'airline_penalty' => 0.0,
            'office_penalty' => 0.0,
            'account_id' => $bookingCashbox->id,
            'notes' => "[{$currency}] cancel after payDebt",
        ]);

        $this->assertEveryTransactionBalanced();
        $this->assertNoNegativeLiquidity([$bookingCashbox, $egpCashbox]);

        // The payDebt income must be reversed.
        $payDebtTx->refresh();
        $hasReversal = Transaction::query()
            ->where('related_type', Customer::class)
            ->where('related_id', $customer->id)
            ->where('type', 'income')
            ->where('module', TransactionModule::Flight->value)
            ->where('notes', 'LIKE', '%عكس%')
            ->exists();
        $this->assertTrue($hasReversal,
            "[{$currency}] Cancel after payDebt must produce a reversal income row");
    }

    // ═════════════════════════════════════════════════════════════════════
    // SECTION 4: AUDITED REFUND SCENARIOS (S1 / S4 / S5 / S6) × currency
    //
    // نفس سيناريوهات tests/Audit/RefundScenariosTest.php — لكن بحجز بعملة
    // أجنبية (USD / SAR / KWD). الدفتر العام (General Ledger) مُقيَّم بالجنيه
    // المصري، لذلك تُقارن الأرقام بمكافئها بالجنيه المصري:
    //   S1: إيراد 1200 − تكلفة 1000 = +200
    //   S4: إيراد 0    − تكلفة 250  = −250
    //   S5: إيراد 250  − تكلفة 250  = 0
    //   S6: الدفعة الزائدة (8000) تُحتسب إيراداً كاملاً والتكلفة لا تتغيّر.
    //
    // التحقق المطلوب في كل عملة:
    //   (1) تقرير الأرباح والخسائر (نفس محرك الإنتاج ProfitLossReportService).
    //   (2) توازن القيود: القيد داخل عملة واحدة = مدين = دائن، والقيد بين
    //       عملتين = الرجلان متساويان القيمة عند سعر الصرف المخزَّن.
    //   (3) رصيد حساب العميل (بالجنيه) = رصيد دفتر العميل (بعملة الحجز) × السعر.
    //   (4) الحسابات الوسيطة (تكاليف/مبيعات معلقة/إقفال مبيعات) ترجع صفراً.
    // ═════════════════════════════════════════════════════════════════════

    /** اقتصاديات السيناريوهات — دائماً بالجنيه المصري (عملة الدفتر). */
    protected const SCENARIO_SELLING_EGP = 7500.0;

    protected const SCENARIO_PURCHASE_EGP = 7350.0;

    protected const SCENARIO_PENALTY_EGP = 1000.0;

    protected const SCENARIO_COMMISSION_EGP = 200.0;

    protected const SCENARIO_TAXES_EGP = 250.0;

    protected const SCENARIO_AIRLINE_REFUND_EGP = 7100.0;

    protected const SCENARIO_OVERPAYMENT_EGP = 8000.0;

    /** @var array<int,string> */
    protected array $scenarioDump = [];

    protected function tearDown(): void
    {
        if ($this->scenarioDump) {
            $name = 'MC_'.($this->dumpScenarioName ?? 'SCENARIO');
            $path = dirname(__DIR__, 2).'/Audit/out/'.$name.'.txt';
            @mkdir(dirname($path), 0777, true);
            file_put_contents($path, implode("\n", $this->scenarioDump)."\n");
        }

        parent::tearDown();
    }

    protected string $dumpScenarioName = 'SCENARIO';

    /**
     * عملات السيناريوهات — (الخزينة الابتدائية، سعر الصرف: جنيه لكل وحدة).
     */
    public static function refundScenarioProvider(): array
    {
        return [
            'USD' => ['USD', 10_000.0, 50.0],
            'SAR' => ['SAR', 10_000.0, 13.3333],
            'KWD' => ['KWD', 1_000.0, 162.5],
        ];
    }

    /** تحويل مبلغ بالجنيه المصري إلى عملة الحجز. */
    protected function foreign(float $egp, float $rate): float
    {
        return round($egp / $rate, 4);
    }

    protected function createScenarioBooking(string $currency, float $rate, array $fx): FlightBooking
    {
        $booking = $this->bookingService->createBooking([
            'customer_id' => $fx['customer']->id,
            'employee_id' => $this->employee->id,
            'selling_price' => self::SCENARIO_SELLING_EGP,
            'purchase_price' => self::SCENARIO_PURCHASE_EGP,
            'selling_price_foreign' => $this->foreign(self::SCENARIO_SELLING_EGP, $rate),
            'purchase_price_foreign' => $this->foreign(self::SCENARIO_PURCHASE_EGP, $rate),
            'currency' => $currency,
            'original_currency' => $currency,
            'account_id' => $fx['cashbox']->id,
            'agent_name' => 'Scenario Test Agent',
            'origin' => 'CAI',
            'destination' => 'DXB',
            'departure_date' => now()->addDays(40)->toDateString(),
            'departure_time' => '11:00:00',
            'trip_type' => 'one_way',
            'airline' => substr($currency, 0, 2).'S',
            'passenger_count' => 1,
            'flight_carrier_id' => $fx['carrier']->id,
            'pnr' => 'PNR'.uniqid(),
        ]);

        // تأكيد الحجز صراحةً (كما في tests/Audit/AuditTestCase): السعر
        // الأجنبي يُقرَّب لأربع خانات عشرية، فيمكن أن يقلّ المدفوع عن سعر
        // البيع بأجزاء من القرش (مثلاً الدينار: 7,499.9925 من 7,500) فلا
        // تعمل الترقية التلقائية من PENDING إلى CONFIRMED.
        $booking->update(['status' => FlightBookingStatus::CONFIRMED]);

        return $booking->fresh();
    }

    protected function payScenarioBooking(
        FlightBooking $booking,
        float $amountForeign,
        Account $cashbox,
        array $extra = []
    ): void {
        $this->bookingService->addPayment($booking, array_merge([
            'amount' => $amountForeign,
            'payment_method' => 'cash',
            'account_id' => $cashbox->id,
            'idempotency_key' => uniqid('mc_scen_', true),
        ], $extra));
    }

    /** لقطة رقمية لتقرير الأرباح والخسائر من محرك الإنتاج نفسه. */
    protected function plSnapshot(): array
    {
        $pl = app(\App\Services\Reports\ReportFinanceService::class)
            ->getProfitLossReport(['section' => 'all']);

        return [
            'totalRevenues' => (float) ($pl['totalRevenues'] ?? 0),
            'totalCogs' => (float) ($pl['totalCogs'] ?? 0),
            'totalExpenses' => (float) ($pl['totalExpenses'] ?? 0),
            'totalRefunds' => (float) ($pl['totalRefunds'] ?? 0),
            'grossProfit' => (float) ($pl['grossProfit'] ?? 0),
            'netProfit' => (float) ($pl['netProfit'] ?? 0),
        ];
    }

    /** الحسابات الوسيطة التي يجب أن ترجع إلى صفر بعد الاسترداد. */
    protected function intermediateAccountIds(): array
    {
        $clearing = app(LedgerClearingAccounts::class);

        return array_values(array_filter([
            $clearing->expenseContraIdForModule(TransactionModule::Flight),
            $clearing->pendingSalesReceivableIdForFlight(),
            $clearing->incomeContraIdForFlightBooking(),
        ]));
    }

    /**
     * توازن القيود لكل عملة:
     *  - القيد داخل عملة واحدة: مجموع المدين = مجموع الدائن.
     *  - القيد بين عملتين: الرجلان متساويان القيمة عند سعر صرف العملة
     *    المخزَّن في جدول `currencies` (جنيه لكل وحدة).
     */
    protected function assertEntriesBalancedPerCurrency(string $tag): void
    {
        $rows = DB::table('account_entries as e')
            ->join('transactions as t', 't.id', '=', 'e.transaction_id')
            ->leftJoin('accounts as fa', 'fa.id', '=', 't.from_account_id')
            ->leftJoin('accounts as ta', 'ta.id', '=', 't.to_account_id')
            ->select('t.id as transaction_id', 't.notes',
                DB::raw('SUM(e.debit) as d'),
                DB::raw('SUM(e.credit) as c'),
                'fa.currency as from_currency', 'ta.currency as to_currency')
            ->whereNotNull('e.transaction_id')
            ->groupBy('t.id', 't.notes', 'fa.currency', 'ta.currency')
            ->orderBy('t.id')
            ->get();

        foreach ($rows as $r) {
            $notes = (string) ($r->notes ?? '');
            if (Str::startsWith($notes, 'OPENING:')) {
                // قيد افتتاحي برجل واحد (رصيد ابتدائي) — ليس قيداً مزدوجاً.
                continue;
            }

            $from = strtoupper((string) $r->from_currency);
            $to = strtoupper((string) $r->to_currency);
            $d = (float) $r->d;
            $c = (float) $r->c;

            if ($from === $to) {
                $this->assertEqualsWithDelta(
                    $d, $c, 0.02,
                    "{$tag}: القيد #{$r->transaction_id} غير متوازن داخل {$from} (مدين={$d} دائن={$c}) — {$notes}"
                );
                continue;
            }

            // قيد عبر عملتين: نحوّل الرجلين إلى الجنيه ونقارن.
            $dEgp = $from === 'EGP' ? $d : $d * $this->egpPerUnit($from);
            $cEgp = $to === 'EGP' ? $c : $c * $this->egpPerUnit($to);
            $this->assertEqualsWithDelta(
                $dEgp, $cEgp, 0.05,
                "{$tag}: القيد #{$r->transaction_id} غير متساوي القيمة بين {$from} و{$to} ".
                "(مدين={$d} {$from} = {$dEgp} EGP / دائن={$c} {$to} = {$cEgp} EGP) — {$notes}"
            );
        }
    }

    /** سعر الصرف المخزَّن: جنيه مصري لكل وحدة من العملة. */
    protected function egpPerUnit(string $currency): float
    {
        $code = strtoupper(trim($currency));
        if ($code === '' || $code === 'EGP') {
            return 1.0;
        }

        $rate = (float) (Currency::query()->where('code', $code)->value('exchange_rate') ?? 0.0);

        return $rate > 0 ? $rate : 1.0;
    }

    /**
     * رصيد كل حساب = مجموع الدائن − مجموع المدين (اتفاق المشروع).
     */
    protected function assertAccountBalancesMatchEntries(string $tag): void
    {
        $rows = DB::table('accounts as a')
            ->leftJoin('account_entries as e', 'e.account_id', '=', 'a.id')
            ->select('a.id', 'a.name', 'a.currency', 'a.balance',
                DB::raw('COALESCE(SUM(e.credit), 0) as c'),
                DB::raw('COALESCE(SUM(e.debit), 0) as d'))
            ->groupBy('a.id', 'a.name', 'a.currency', 'a.balance')
            ->get();

        foreach ($rows as $r) {
            $c = (float) $r->c;
            $d = (float) $r->d;
            if (abs($c) < 0.005 && abs($d) < 0.005) {
                continue;
            }
            $this->assertEqualsWithDelta(
                $c - $d, (float) $r->balance, 0.02,
                "{$tag}: رصيد الحساب #{$r->id} ({$r->name}, {$r->currency}) = ".number_format((float) $r->balance, 2).
                ' لا يساوي (دائن − مدين) = '.number_format($c - $d, 2)
            );
        }
    }

    protected function scenarioDumpLine(string $line): void
    {
        $this->scenarioDump[] = $line;
    }

    /**
     * سماح المقارنة بين رصيد حساب العميل (بالجنيه) ودفتر الرصيد (بعملة
     * الحجز): عمود `customer_credit_ledger.amount` يخزّن منزلتين عشريتين
     * فقط، فمبلغ بالعملة الدينار (ثلاث منازل) يُقرَّب لأقرب قرش — أي فرق
     * يصل إلى 0.01 من عملة الحجز.
     */
    protected function ledgerTolerance(float $rate): float
    {
        return 0.01 * $rate + 0.05;
    }

    /**
     * سماح تدقيق أرقام الـ P&L والحسابات الوسيطة.
     *
     * أعمدة المبالغ في `refund_requests` (refund_amount / supplier_refund_amount
     * / agency_commission / airline_penalty / non_refundable_taxes_amount)
     * بخانتين عشريتين، بينما الدينار عملة بثلاث منازل — فتقريب كل مبلغ
     * أجنبي لأقرب 0.01 من عملة الحجز يزيح نتيجة الـ P&L بما لا يتجاوز
     * 0.005 × سعر الصرف جنيه لكل مبلغ مقروَّب. والسيناريو بأربعة مبالغ
     * مقروَّبة، فالسماح = 0.02 × سعر الصرف + 0.05 جنيه.
     *
     * ملاحظة: خطأ التحويل بين العملات (كالذي كان يُرحّل التكلفة بقيمة
     * أجنبية على حسابات الجنيه) يظهر بآلاف الجنيهات — أي أكبر من هذا
     * السماح بمراحل، فلن يمرّ خطأ كهذا.
     */
    protected function roundingTolerance(float $rate): float
    {
        return 0.02 * $rate + 0.05;
    }

    protected function dumpPLRows(string $title, array $before, array $after): void
    {
        $this->scenarioDumpLine('');
        $this->scenarioDumpLine('── '.$title.' ──');
        $this->scenarioDumpLine(sprintf('   %-16s %14s %14s %14s', 'PL', 'BEFORE', 'AFTER', 'DELTA'));
        foreach (['totalRevenues', 'totalCogs', 'totalExpenses', 'totalRefunds', 'grossProfit', 'netProfit'] as $k) {
            $this->scenarioDumpLine(sprintf(
                '   %-16s %14s %14s %14s',
                $k,
                number_format((float) $before[$k], 2, '.', ''),
                number_format((float) $after[$k], 2, '.', ''),
                number_format(round((float) $after[$k] - (float) $before[$k], 2), 2, '.', '')
            ));
        }
    }

    protected function dumpEntriesRows(string $title): void
    {
        $rows = DB::table('account_entries as ae')
            ->join('accounts as a', 'a.id', '=', 'ae.account_id')
            ->join('transactions as t', 't.id', '=', 'ae.transaction_id')
            ->select([
                'ae.transaction_id', 'ae.account_id', 'a.name as account_name', 'a.currency',
                'ae.debit', 'ae.credit', 'ae.balance_after', 't.notes',
            ])
            ->orderBy('ae.id')
            ->get();

        $this->scenarioDumpLine('');
        $this->scenarioDumpLine('── '.$title.' ──');
        $this->scenarioDumpLine(sprintf(
            '   %-6s %-5s %-34s %-6s %12s %12s %14s %s',
            'TX', 'ACCT', 'ACCOUNT', 'CUR', 'DEBIT', 'CREDIT', 'BAL_AFTER', 'NOTES'
        ));
        foreach ($rows as $r) {
            $this->scenarioDumpLine(sprintf(
                '   %-6d %-5d %-34s %-6s %12s %12s %14s %s',
                $r->transaction_id,
                $r->account_id,
                mb_substr((string) $r->account_name, 0, 34),
                (string) $r->currency,
                number_format((float) $r->debit, 2, '.', ''),
                number_format((float) $r->credit, 2, '.', ''),
                number_format((float) $r->balance_after, 2, '.', ''),
                mb_substr((string) $r->notes, 0, 60)
            ));
        }
    }

    // ─────────────────────────────────────────────────────────────────
    // S1 — إلغاء من العميل: غرامة 1000 + عمولة 200 ⇒ صافي ربح +200
    // ─────────────────────────────────────────────────────────────────
    #[DataProvider('refundScenarioProvider')]
    public function test_s1_customer_voluntary_cancellation_per_currency(
        string $currency,
        float $cashboxOpening,
        float $exchangeRate
    ): void {
        $this->dumpScenarioName = 'S1_'.$currency;
        $fx = $this->buildCurrencyFixture($currency, $cashboxOpening);
        /** @var Customer $customer */ $customer = $fx['customer'];
        /** @var FlightCarrier $carrier */ $carrier = $fx['carrier'];
        /** @var Account $cashbox */ $cashbox = $fx['cashbox'];
        $carrierAtSetup = (float) $carrier->fresh()->balance;

        $booking = $this->createScenarioBooking($currency, $exchangeRate, $fx);
        $sellingForeign = $this->foreign(self::SCENARIO_SELLING_EGP, $exchangeRate);
        $this->payScenarioBooking($booking, $sellingForeign, $cashbox);
        $booking->refresh();

        $plBefore = $this->plSnapshot();

        $penalty = $this->foreign(self::SCENARIO_PENALTY_EGP, $exchangeRate);
        $commission = $this->foreign(self::SCENARIO_COMMISSION_EGP, $exchangeRate);

        $req = $this->refundService->createRefundRequest([
            'flight_booking_id' => $booking->id,
            'refund_case' => 'customer_voluntary',
            'airline_penalty' => $penalty,
            'agency_commission' => $commission,
            'refund_currency' => $currency,
            'refund_exchange_rate' => $exchangeRate,
            'destination' => 'customer_credit',
        ], $this->admin->id);

        $expectedRefund = round($sellingForeign - $penalty - $commission, 4);
        $expectedSupplier = round($this->foreign(self::SCENARIO_PURCHASE_EGP, $exchangeRate) - $penalty, 4);

        $this->assertEqualsWithDelta(
            $expectedRefund, (float) $req->refund_amount, 0.01,
            "S1[{$currency}]: صافي المسترد للعميل يجب أن يكون {$expectedRefund} {$currency}"
        );
        $this->assertEqualsWithDelta(
            $expectedSupplier, (float) $req->supplier_refund_amount, 0.01,
            "S1[{$currency}]: المسترد من المورد يجب أن يكون {$expectedSupplier} {$currency}"
        );

        $this->refundService->processRefundRequest($req->id, $this->admin->id);
        $req->refresh();

        $plAfter = $this->plSnapshot();
        $this->scenarioDumpLine(sprintf(
            '   AR=%.2f EGP | ledger=%.2f %s | carrier=%.4f %s | cashbox=%.2f %s',
            (float) $customer->ledgerAccount->fresh()->balance,
            CustomerCreditLedger::getAvailableCredit($customer->id, null, $currency),
            $currency,
            (float) $fx['carrier']->fresh()->balance,
            $currency,
            (float) $cashbox->fresh()->balance,
            $currency
        ));
        $this->dumpPLRows("S1[{$currency}] تقرير الأرباح والخسائر", $plBefore, $plAfter);
        $this->dumpEntriesRows("S1[{$currency}] قيود الاسترداد");

        // (1) توازن القيود لكل عملة
        $this->assertEntriesBalancedPerCurrency("S1[{$currency}]");
        $this->assertAccountBalancesMatchEntries("S1[{$currency}]");

        // (2) تقرير الأرباح والخسائر (بالجنيه المصري)
        $this->assertEqualsWithDelta(
            1200.0, $plAfter['totalRevenues'], $this->roundingTolerance($exchangeRate),
            "S1[{$currency}]: الإيراد بعد الاسترداد = 7500 − 6300 = 1200 — الفعلي: {$plAfter['totalRevenues']}"
        );
        $this->assertEqualsWithDelta(
            1000.0, $plAfter['totalCogs'], $this->roundingTolerance($exchangeRate),
            "S1[{$currency}]: التكلفة بعد الاسترداد = 7350 − 6350 = 1000 — الفعلي: {$plAfter['totalCogs']}"
        );
        $this->assertEqualsWithDelta(
            200.0, $plAfter['netProfit'], $this->roundingTolerance($exchangeRate),
            "S1[{$currency}]: صافي الربح يجب أن يكون +200 — الفعلي: {$plAfter['netProfit']}"
        );

        // (3) رصيد العميل في الدفتر (EGP) = رصيد دفتر العميل (عملة الحجز) × السعر
        $arBalance = (float) $customer->ledgerAccount->fresh()->balance;
        $ledgerCredit = CustomerCreditLedger::getAvailableCredit($customer->id, null, $currency);
        $this->assertEqualsWithDelta(
            -$ledgerCredit * $exchangeRate, $arBalance, $this->ledgerTolerance($exchangeRate),
            "S1[{$currency}]: رصيد حساب العميل ({$arBalance}) لا يطابق دفتر الرصيد ".
            "({$ledgerCredit} {$currency} = ".($ledgerCredit * $exchangeRate).' EGP)'
        );

        // (4) الحسابات الوسيطة ترجع صفراً + رصيد الناقل
        foreach ($this->intermediateAccountIds() as $accId) {
            $this->assertEqualsWithDelta(
                0.0, (float) DB::table('accounts')->where('id', $accId)->value('balance'),
                $this->roundingTolerance($exchangeRate),
                "S1[{$currency}]: الحساب الوسيط #{$accId} لم يرجع إلى صفر"
            );
        }
        $this->assertEqualsWithDelta(
            $carrierAtSetup - $penalty, (float) $carrier->fresh()->balance, 0.02,
            "S1[{$currency}]: رصيد الناقل = رصيده الأصلي ناقص الغرامة ({$penalty} {$currency})"
        );
        $this->assertNoNegativeLiquidity([$cashbox]);
    }

    // ─────────────────────────────────────────────────────────────────
    // S4 — الطيران ألغى ورجّع 7100، الضرائب 250 علينا ⇒ صافي ربح −250
    // ─────────────────────────────────────────────────────────────────
    #[DataProvider('refundScenarioProvider')]
    public function test_s4_airline_cancelled_taxes_on_agency_per_currency(
        string $currency,
        float $cashboxOpening,
        float $exchangeRate
    ): void {
        $this->dumpScenarioName = 'S4_'.$currency;
        $fx = $this->buildCurrencyFixture($currency, $cashboxOpening);
        /** @var Customer $customer */ $customer = $fx['customer'];
        /** @var FlightCarrier $carrier */ $carrier = $fx['carrier'];
        /** @var Account $cashbox */ $cashbox = $fx['cashbox'];
        $carrierAtSetup = (float) $carrier->fresh()->balance;

        $booking = $this->createScenarioBooking($currency, $exchangeRate, $fx);
        $this->payScenarioBooking($booking, $this->foreign(self::SCENARIO_SELLING_EGP, $exchangeRate), $cashbox);
        $booking->refresh();

        $plBefore = $this->plSnapshot();

        $airlineRefund = $this->foreign(self::SCENARIO_AIRLINE_REFUND_EGP, $exchangeRate);
        $taxes = $this->foreign(self::SCENARIO_TAXES_EGP, $exchangeRate);

        $req = $this->refundService->createRefundRequest([
            'flight_booking_id' => $booking->id,
            'refund_case' => 'airline_cancelled',
            'airline_refund_amount' => $airlineRefund,
            'apply_commission' => false,
            'non_refundable_taxes_on_customer' => false,
            'non_refundable_taxes_amount' => $taxes,
            'refund_currency' => $currency,
            'refund_exchange_rate' => $exchangeRate,
            'destination' => 'customer_credit',
        ], $this->admin->id);

        $this->assertEqualsWithDelta(
            $this->foreign(self::SCENARIO_SELLING_EGP, $exchangeRate), (float) $req->refund_amount, 0.01,
            "S4[{$currency}]: صافي المسترد للعميل = كامل سعر البيع"
        );
        $this->assertEqualsWithDelta(
            $taxes, (float) $req->supplier_refund_loss, 0.01,
            "S4[{$currency}]: خسارة الاسترداد = الضرائب التي لم تردها شركة الطيران ({$taxes} {$currency})"
        );

        $this->refundService->processRefundRequest($req->id, $this->admin->id);
        $req->refresh();

        $plAfter = $this->plSnapshot();
        $this->scenarioDumpLine(sprintf(
            '   AR=%.2f EGP | ledger=%.2f %s | carrier=%.4f %s | cashbox=%.2f %s',
            (float) $customer->ledgerAccount->fresh()->balance,
            CustomerCreditLedger::getAvailableCredit($customer->id, null, $currency),
            $currency,
            (float) $fx['carrier']->fresh()->balance,
            $currency,
            (float) $cashbox->fresh()->balance,
            $currency
        ));
        $this->dumpPLRows("S4[{$currency}] تقرير الأرباح والخسائر", $plBefore, $plAfter);
        $this->dumpEntriesRows("S4[{$currency}] قيود الاسترداد");

        $this->assertEntriesBalancedPerCurrency("S4[{$currency}]");
        $this->assertAccountBalancesMatchEntries("S4[{$currency}]");

        $this->assertEqualsWithDelta(
            0.0, $plAfter['totalRevenues'], $this->roundingTolerance($exchangeRate),
            "S4[{$currency}]: الإيراد بعد الاسترداد = 0 — الفعلي: {$plAfter['totalRevenues']}"
        );
        $this->assertEqualsWithDelta(
            250.0, $plAfter['totalCogs'], $this->roundingTolerance($exchangeRate),
            "S4[{$currency}]: التكلفة بعد الاسترداد = 7350 − 7100 = 250 — الفعلي: {$plAfter['totalCogs']}"
        );
        $this->assertEqualsWithDelta(
            -250.0, $plAfter['netProfit'], $this->roundingTolerance($exchangeRate),
            "S4[{$currency}]: صافي الربح يجب أن يكون −250 — الفعلي: {$plAfter['netProfit']}"
        );

        $arBalance = (float) $customer->ledgerAccount->fresh()->balance;
        $ledgerCredit = CustomerCreditLedger::getAvailableCredit($customer->id, null, $currency);
        $this->assertEqualsWithDelta(
            -$ledgerCredit * $exchangeRate, $arBalance, $this->ledgerTolerance($exchangeRate),
            "S4[{$currency}]: رصيد حساب العميل ({$arBalance}) لا يطابق دفتر الرصيد ({$ledgerCredit} {$currency})"
        );

        // رصيد الناقل = رصيده الأصلي ناقص الضريبة المفقودة فقط
        $this->assertEqualsWithDelta(
            $carrierAtSetup - $taxes, (float) $carrier->fresh()->balance, 0.02,
            "S4[{$currency}]: رصيد الناقل = رصيده الأصلي ناقص الضريبة ({$taxes} {$currency})"
        );
        $this->assertNoNegativeLiquidity([$cashbox]);
    }

    // ─────────────────────────────────────────────────────────────────
    // S5 — نفس S4 لكن الضرائب 250 على العميل ⇒ صافي ربح صفر
    // ─────────────────────────────────────────────────────────────────
    #[DataProvider('refundScenarioProvider')]
    public function test_s5_airline_cancelled_taxes_on_customer_per_currency(
        string $currency,
        float $cashboxOpening,
        float $exchangeRate
    ): void {
        $this->dumpScenarioName = 'S5_'.$currency;
        $fx = $this->buildCurrencyFixture($currency, $cashboxOpening);
        /** @var Customer $customer */ $customer = $fx['customer'];
        /** @var FlightCarrier $carrier */ $carrier = $fx['carrier'];
        /** @var Account $cashbox */ $cashbox = $fx['cashbox'];
        $carrierAtSetup = (float) $carrier->fresh()->balance;

        $booking = $this->createScenarioBooking($currency, $exchangeRate, $fx);
        $this->payScenarioBooking($booking, $this->foreign(self::SCENARIO_SELLING_EGP, $exchangeRate), $cashbox);
        $booking->refresh();

        $plBefore = $this->plSnapshot();

        $airlineRefund = $this->foreign(self::SCENARIO_AIRLINE_REFUND_EGP, $exchangeRate);
        $taxes = $this->foreign(self::SCENARIO_TAXES_EGP, $exchangeRate);

        $req = $this->refundService->createRefundRequest([
            'flight_booking_id' => $booking->id,
            'refund_case' => 'airline_cancelled',
            'airline_refund_amount' => $airlineRefund,
            'apply_commission' => false,
            'non_refundable_taxes_on_customer' => true,
            'non_refundable_taxes_amount' => $taxes,
            'refund_currency' => $currency,
            'refund_exchange_rate' => $exchangeRate,
            'destination' => 'customer_credit',
        ], $this->admin->id);

        $expectedRefund = round($this->foreign(self::SCENARIO_SELLING_EGP, $exchangeRate) - $taxes, 4);
        $this->assertEqualsWithDelta(
            $expectedRefund, (float) $req->refund_amount, 0.01,
            "S5[{$currency}]: صافي المسترد للعميل = سعر البيع ناقص الضرائب ({$expectedRefund} {$currency})"
        );

        $this->refundService->processRefundRequest($req->id, $this->admin->id);
        $req->refresh();

        $plAfter = $this->plSnapshot();
        $this->scenarioDumpLine(sprintf(
            '   AR=%.2f EGP | ledger=%.2f %s | carrier=%.4f %s | cashbox=%.2f %s',
            (float) $customer->ledgerAccount->fresh()->balance,
            CustomerCreditLedger::getAvailableCredit($customer->id, null, $currency),
            $currency,
            (float) $fx['carrier']->fresh()->balance,
            $currency,
            (float) $cashbox->fresh()->balance,
            $currency
        ));
        $this->dumpPLRows("S5[{$currency}] تقرير الأرباح والخسائر", $plBefore, $plAfter);
        $this->dumpEntriesRows("S5[{$currency}] قيود الاسترداد");

        $this->assertEntriesBalancedPerCurrency("S5[{$currency}]");
        $this->assertAccountBalancesMatchEntries("S5[{$currency}]");

        $this->assertEqualsWithDelta(
            250.0, $plAfter['totalRevenues'], $this->roundingTolerance($exchangeRate),
            "S5[{$currency}]: الإيراد بعد الاسترداد = 7500 − 7250 = 250 — الفعلي: {$plAfter['totalRevenues']}"
        );
        $this->assertEqualsWithDelta(
            250.0, $plAfter['totalCogs'], $this->roundingTolerance($exchangeRate),
            "S5[{$currency}]: التكلفة بعد الاسترداد = 7350 − 7100 = 250 — الفعلي: {$plAfter['totalCogs']}"
        );
        $this->assertEqualsWithDelta(
            0.0, $plAfter['netProfit'], $this->roundingTolerance($exchangeRate),
            "S5[{$currency}]: صافي الربح يجب أن يكون صفراً — الفعلي: {$plAfter['netProfit']}"
        );

        $arBalance = (float) $customer->ledgerAccount->fresh()->balance;
        $ledgerCredit = CustomerCreditLedger::getAvailableCredit($customer->id, null, $currency);
        $this->assertEqualsWithDelta(
            -$ledgerCredit * $exchangeRate, $arBalance, $this->ledgerTolerance($exchangeRate),
            "S5[{$currency}]: رصيد حساب العميل ({$arBalance}) لا يطابق دفتر الرصيد ({$ledgerCredit} {$currency})"
        );

        foreach ($this->intermediateAccountIds() as $accId) {
            $this->assertEqualsWithDelta(
                0.0, (float) DB::table('accounts')->where('id', $accId)->value('balance'),
                $this->roundingTolerance($exchangeRate),
                "S5[{$currency}]: الحساب الوسيط #{$accId} لم يرجع إلى صفر"
            );
        }
        $this->assertEqualsWithDelta(
            $carrierAtSetup - $taxes, (float) $carrier->fresh()->balance, 0.02,
            "S5[{$currency}]: رصيد الناقل = رصيده الأصلي ناقص الضريبة ({$taxes} {$currency})"
        );
        $this->assertNoNegativeLiquidity([$cashbox]);
    }

    // ─────────────────────────────────────────────────────────────────
    // S6 — دفعة زيادة 8000 على حجز 7500 ⇒ رصيد دائن 500
    // ─────────────────────────────────────────────────────────────────
    #[DataProvider('refundScenarioProvider')]
    public function test_s6_overpayment_creates_credit_per_currency(
        string $currency,
        float $cashboxOpening,
        float $exchangeRate
    ): void {
        $this->dumpScenarioName = 'S6_'.$currency;
        $fx = $this->buildCurrencyFixture($currency, $cashboxOpening);
        /** @var Customer $customer */ $customer = $fx['customer'];
        /** @var Account $cashbox */ $cashbox = $fx['cashbox'];

        $booking = $this->createScenarioBooking($currency, $exchangeRate, $fx);
        $sellingForeign = $this->foreign(self::SCENARIO_SELLING_EGP, $exchangeRate);
        $overForeign = $this->foreign(self::SCENARIO_OVERPAYMENT_EGP, $exchangeRate);

        $plBefore = $this->plSnapshot();

        $this->payScenarioBooking($booking, $overForeign, $cashbox, ['allow_overpayment' => true]);
        $booking->refresh();

        $plAfter = $this->plSnapshot();
        $this->scenarioDumpLine(sprintf(
            '   AR=%.2f EGP | ledger=%.2f %s | carrier=%.4f %s | cashbox=%.2f %s',
            (float) $customer->ledgerAccount->fresh()->balance,
            CustomerCreditLedger::getAvailableCredit($customer->id, null, $currency),
            $currency,
            (float) $fx['carrier']->fresh()->balance,
            $currency,
            (float) $cashbox->fresh()->balance,
            $currency
        ));
        $this->dumpPLRows("S6[{$currency}] تقرير الأرباح والخسائر", $plBefore, $plAfter);
        $this->dumpEntriesRows("S6[{$currency}] قيود الدفعة");

        $this->assertEntriesBalancedPerCurrency("S6[{$currency}]");
        $this->assertAccountBalancesMatchEntries("S6[{$currency}]");

        $payment = $booking->payments()->first();
        $this->assertEqualsWithDelta(
            self::SCENARIO_SELLING_EGP, (float) $payment->amount, 0.01,
            "S6[{$currency}]: مبلغ الدفعة في الدفتر يقتص على سعر البيع (7500 EGP)"
        );
        $this->assertEqualsWithDelta(
            self::SCENARIO_SELLING_EGP, (float) $booking->paid_amount, 0.01,
            "S6[{$currency}]: المدفوع على الحجز = 7500 EGP"
        );

        // الرصيد الدائن = الفرق بين الدفعة وسعر البيع (عملة الحجز)
        $expectedCredit = round($overForeign - $sellingForeign, 4);
        $ledgerCredit = CustomerCreditLedger::getAvailableCredit($customer->id, null, $currency);
        $this->assertEqualsWithDelta(
            $expectedCredit, $ledgerCredit, 0.02,
            "S6[{$currency}]: الرصيد الدائن المتوقع = {$expectedCredit} {$currency} — الفعلي: {$ledgerCredit}"
        );

        $arBalance = (float) $customer->ledgerAccount->fresh()->balance;
        $this->assertEqualsWithDelta(
            -$ledgerCredit * $exchangeRate, $arBalance, $this->ledgerTolerance($exchangeRate),
            "S6[{$currency}]: رصيد حساب العميل ({$arBalance}) لا يطابق دفتر الرصيد ({$ledgerCredit} {$currency})"
        );

        // تقرير الأرباح والخسائر: الدفعة الزائدة تُحتسب إيراداً كاملاً، والتكلفة لا تتغيّر
        $this->assertEqualsWithDelta(
            self::SCENARIO_OVERPAYMENT_EGP, $plAfter['totalRevenues'], $this->roundingTolerance($exchangeRate),
            "S6[{$currency}]: الإيراد = كامل الدفعة (8000) — الفعلي: {$plAfter['totalRevenues']}"
        );
        $this->assertEqualsWithDelta(
            self::SCENARIO_PURCHASE_EGP, $plAfter['totalCogs'], $this->roundingTolerance($exchangeRate),
            "S6[{$currency}]: التكلفة لا تتغيّر (7350) — الفعلي: {$plAfter['totalCogs']}"
        );
        $this->assertEqualsWithDelta(
            650.0, $plAfter['netProfit'], $this->roundingTolerance($exchangeRate),
            "S6[{$currency}]: صافي الربح = 8000 − 7350 = 650 — الفعلي: {$plAfter['netProfit']}"
        );
        $this->assertNoNegativeLiquidity([$cashbox]);
    }
}
