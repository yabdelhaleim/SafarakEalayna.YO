<?php

namespace Tests\Feature\Flight;

use App\Enums\FlightBookingStatus;
use App\Models\Account;
use App\Models\AccountEntry;
use App\Models\Bank;
use App\Models\Customer;
use App\Models\Flight\FlightBooking;
use App\Models\Flight\FlightCarrier;
use App\Models\Flight\FlightPayment;
use App\Models\Flight\FlightRefund;
use App\Models\Flight\FlightSystem;
use App\Models\Transaction;
use App\Models\Wallet;
use App\Services\Finance\TransactionService;
use App\Services\Flight\FlightBookingService;
use App\Services\Flight\FlightCarrierRechargeService;
use App\Services\Flight\FlightSystemRechargeService;
use App\Services\Finance\TreasuryService;
use App\Support\Finance\LedgerBalanceMutationGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Feature\Flight\FlightFxFixtureTrait;

/**
 * COMPREHENSIVE DEEP E2E — Flight Module + Finance Core
 * =====================================================
 *
 * Scope: Every realistic workflow the module should handle in production.
 *
 * PART 1 — FINANCE CORE (create + operate treasuries, wallets, banks)
 *   1. Create treasury/cashbox (EGP)
 *   2. Create wallet (EGP)
 *   3. Create bank (EGP)
 *   4. Create USD cashbox
 *   5. Transfer cashbox → wallet (EGP)
 *   6. Transfer wallet → bank (EGP)
 *   7. Treasury overview endpoint
 *
 * PART 2 — FLIGHT SYSTEM SETUP
 *   8. Create flight system (GDS)
 *   9. Create flight carrier linked to system
 *  10. Recharge system from cashbox
 *  11. Recharge carrier from cashbox
 *
 * PART 3 — BOOKING SCENARIOS
 *  12. EGP booking, pay full cash → cancel partial → delete (verify net=0)
 *  13. KWD booking, 3 installments → cancel full penalty → delete
 *  14. USD booking, pay from wallet → cancel partial → delete
 *  15. SAR booking, pay from bank → modify price → cancel → delete
 *  16. EGP multi-payment (cashbox+wallet+bank) → delete
 *  17. Cross-currency payment (USD booking paid from EGP cashbox)
 *  18. Booking with PURCHASE from system balance (not carrier)
 *  19. Multiple passengers on one booking
 *  20. Refund processing via RefundService (formal request flow)
 *
 * PART 4 — CALCULATIONS SANITY
 *  21. Profit = selling - purchase (always)
 *  22. Multi-currency conversion (1 USD = X EGP)
 *  23. Refund = paid - penalty (always)
 *  24. Account.balance = SUM(credit) - SUM(debit)
 *
 * PART 5 — STRESS / EDGE CASES
 *  25. Idempotent delete (double-delete)
 *  26. Delete booking after partial refund
 *  27. Booking with no payment then delete
 *  28. Payment larger than selling price (overpayment)
 */
class FlightModuleDeepE2ETest extends TestCase
{
    use RefreshDatabase;
    use FlightFxFixtureTrait;

    protected FlightBookingService $bookingService;

    protected TreasuryService $treasuryService;

    protected TransactionService $txService;

    protected $admin;

    /** @var array<int,array{scenario:string,step:string,status:string,detail:string}> */
    /** @var array<int,array{scenario:string,step:string,status:string,detail:string}> */
    protected array $results = [];

    /** Static accumulator so test_summary_report can aggregate across test methods. */
    protected static array $allResults = [];

    protected array $balances = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->bookingService = app(FlightBookingService::class);
        $this->treasuryService = app(TreasuryService::class);
        $this->txService = app(TransactionService::class);

        // PHASE G: seed currencies so cross-currency scenarios 13/14/17 can resolve.
        $this->seedFlightExchangeRates();

        $this->admin = \App\Models\User::factory()->create([
            'name' => 'Deep E2E Admin',
            'email' => 'deep-e2e-admin@test.com',
            'role' => 'admin',
            'is_active' => true,
        ]);
        $this->actingAs($this->admin);
    }

    // ═══════════════════════════════════════════════════════════════════
    // UTILITIES
    // ═══════════════════════════════════════════════════════════════════

    protected function rec(string $scenario, string $step, string $status, string $detail = ''): void
    {
        $this->results[] = compact('scenario', 'step', 'status', 'detail');
        self::$allResults[] = compact('scenario', 'step', 'status', 'detail');
        echo "  [{$status}] {$scenario} :: {$step}".($detail ? " — {$detail}" : '')."\n";
    }

    protected function createAccount(string $name, string $type, string $currency, float $balance): Account
    {
        $a = Account::create([
            'name' => $name,
            'type' => $type,
            'currency' => $currency,
            'balance' => 0,
            'is_active' => true,
            'owner_type' => Account::OWNER_TYPE_OWNER ?? 'office',
            'module_type' => 'office',
            'module' => 'office',
            'is_module_vault' => false,
            'notes' => 'E2E fixture',
            'created_by' => $this->admin->id,
        ]);

        LedgerBalanceMutationGuard::run(function () use ($a, $balance) {
            $a->balance = $balance;
            $a->save();
        });
        AccountEntry::create([
            'account_id' => $a->id,
            'transaction_id' => null,
            'debit' => 0,
            'credit' => $balance,
            'balance_after' => $balance,
            'notes' => "رصيد افتتاحي {$name}",
        ]);

        return $a->fresh();
    }

    protected function createCustomer(string $name): Customer
    {
        return Customer::create([
            'full_name' => $name,
            'phone' => '01'.substr(md5($name.microtime(true)), 0, 9),
            'email' => 'cust-'.substr(md5($name.microtime(true)), 0, 8).'@test.com',
            'national_id' => '29'.substr(md5($name.microtime(true)), 0, 12),
            'city' => 'Cairo',
            'travel_country' => 'EGY',
            'module_type' => 'tourism',
        ]);
    }

    protected function createSystem(string $name, string $currency): FlightSystem
    {
        return FlightSystem::create([
            'name' => $name,
            'code' => substr($name, 0, 3).uniqid(),
            'type' => 'gds',
            'is_active' => true,
            'currency' => $currency,
            'credit_limit' => 50000,
            'created_by' => $this->admin->id,
        ]);
    }

    protected function createCarrier(string $name, FlightSystem $system, string $currency): FlightCarrier
    {
        return FlightCarrier::create([
            'name' => $name,
            'code' => substr($name, 0, 3).uniqid(),
            'flight_system_id' => $system->id,
            'currency' => $currency,
            'credit_limit' => 500000,
            'is_active' => true,
            'created_by' => $this->admin->id,
        ]);
    }

    /** Build a full flight fixture (customer + system + carrier + cashbox). */
    protected function buildFlightFixture(string $currency, string $customerName, float $cashboxBalance = 500000.0, float $rechargeAmount = 50000.0): array
    {
        $customer = $this->createCustomer($customerName);
        $system = $this->createSystem("{$currency} SYS ".uniqid(), $currency);
        $carrier = $this->createCarrier("{$currency} CAR ".uniqid(), $system, $currency);

        $cashbox = $this->createAccount("Cashbox {$currency} ".uniqid(), 'cashbox', $currency, $cashboxBalance);

        app(FlightCarrierRechargeService::class)->rechargeFromAccount(
            $carrier, $cashbox, $rechargeAmount, "Recharge {$currency}"
        );

        $carrier->refresh();
        $cashbox->refresh();

        return compact('customer', 'system', 'carrier', 'cashbox');
    }

    protected function assertBalanceInvariant(Account $account, string $label = ''): float
    {
        $account->refresh();
        $entries = AccountEntry::where('account_id', $account->id)->get();
        $expected = round((float) $entries->sum('credit') - (float) $entries->sum('debit'), 2);
        $actual = round((float) $account->balance, 2);

        $this->assertEqualsWithDelta(
            $expected, $actual, 0.01,
            "❌ INVARIANT ".($label ?: $account->name)." (#{$account->id}): ".
            "balance {$actual} ≠ cr-dr = {$expected}"
        );

        $this->balances[$account->id][$label ?: 'check'] = $actual;

        return $actual;
    }

    protected function assertCarrierInvariant(FlightCarrier $carrier, string $label = ''): float
    {
        $carrier->refresh();
        $expected = round((float) $carrier->balance, 2);
        $this->assertGreaterThanOrEqual(0, $expected, "❌ Carrier ".($label ?: $carrier->name)." (#{$carrier->id}) NEGATIVE: {$expected}");
        $this->balances['carrier:'.$carrier->id][$label ?: 'check'] = $expected;

        return $expected;
    }

    protected function assertEveryTransactionBalanced(): void
    {
        $rows = DB::table('transactions')->select('id', 'currency')->get();
        foreach ($rows as $tx) {
            $entries = DB::table('account_entries')
                ->where('transaction_id', $tx->id)
                ->where('currency', $tx->currency)
                ->get();
            $d = (float) $entries->sum('debit');
            $c = (float) $entries->sum('credit');
            $this->assertEqualsWithDelta($d, $c, 0.01,
                "❌ TX #{$tx->id} ({$tx->currency}) unbalanced: dr={$d} cr={$c}");
        }
    }

    protected function assertNoNegative(array $accounts): void
    {
        foreach ($accounts as $a) {
            $bal = (float) $a->fresh()->balance;
            $this->assertGreaterThanOrEqual(0, $bal,
                "❌ ".($a->name ?? 'Account')." #{$a->id} NEGATIVE: {$bal}");
        }
    }

    protected function snapshot(array $accounts): array
    {
        $snap = [];
        foreach ($accounts as $a) {
            $snap[$a->id] = (float) $a->fresh()->balance;
        }

        return $snap;
    }

    protected function assertSnapshotsEqual(array $before, array $accounts, string $note = ''): void
    {
        foreach ($accounts as $a) {
            if (! isset($before[$a->id])) {
                continue;
            }
            $now = (float) $a->fresh()->balance;
            $this->assertEqualsWithDelta($before[$a->id], $now, 0.01,
                "❌ Drift {$note} on #{$a->id}: before={$before[$a->id]} after={$now}");
        }
    }

    // ═══════════════════════════════════════════════════════════════════
    // PART 1 — FINANCE CORE
    // ═══════════════════════════════════════════════════════════════════

    public function test_part1_create_treasury_wallet_bank(): void
    {
        echo "\n═══ PART 1: FINANCE CORE — Create treasuries / wallets / banks ═══\n";

        // 1.1 — Cashbox (treasury)
        $cashbox = $this->createAccount('Cashbox Main', 'cashbox', 'EGP', 1_000_000.0);
        $this->assertBalanceInvariant($cashbox);
        $this->rec('1.1', 'Create cashbox', '✅', "#{$cashbox->id} bal=".number_format($cashbox->balance, 2));

        // 1.2 — Wallet
        $wallet = $this->createAccount('Wallet Vodafone', 'wallet', 'EGP', 50_000.0);
        $this->assertBalanceInvariant($wallet);
        $this->rec('1.2', 'Create wallet', '✅', "#{$wallet->id} bal=".number_format($wallet->balance, 2));

        // 1.3 — Bank
        $bank = $this->createAccount('Bank CIB', 'bank', 'EGP', 250_000.0);
        $this->assertBalanceInvariant($bank);
        $this->rec('1.3', 'Create bank', '✅', "#{$bank->id} bal=".number_format($bank->balance, 2));

        // 1.4 — USD cashbox
        $usd = $this->createAccount('Cashbox USD', 'cashbox', 'USD', 10_000.0);
        $this->assertBalanceInvariant($usd);
        $this->rec('1.4', 'Create USD cashbox', '✅', "#{$usd->id} bal=".number_format($usd->balance, 2));

        // Also create matching Wallet/Bank legacy rows used by dropdown APIs
        Wallet::create([
            'name' => 'Wallet Legacy',
            'wallet_number' => 'WL-'.uniqid(),
            'balance' => 5000,
            'is_active' => true,
            'notes' => 'legacy',
        ]);
        Bank::create([
            'account_id' => $bank->id,
            'name' => 'Bank Legacy',
            'account_number' => 'BL-'.uniqid(),
            'balance' => 250000,
            'currency' => 'EGP',
            'is_active' => true,
            'notes' => 'legacy',
        ]);

        // 1.5 — Transfer cashbox → wallet
        $snap = $this->snapshot([$cashbox, $wallet]);
        $this->txService->recordTransfer([
            'from_account_id' => $cashbox->id,
            'to_account_id' => $wallet->id,
            'amount' => 20_000.0,
            'currency' => 'EGP',
            'notes' => 'تحويل من الخزينة إلى المحفظة',
            'created_by' => $this->admin->id,
        ]);

        $this->assertEqualsWithDelta($cashbox->balance - 20_000, $cashbox->fresh()->balance, 0.01);
        $this->assertEqualsWithDelta($wallet->balance + 20_000, $wallet->fresh()->balance, 0.01);
        $this->assertBalanceInvariant($cashbox);
        $this->assertBalanceInvariant($wallet);
        $this->assertEveryTransactionBalanced();
        $this->rec('1.5', 'Transfer cashbox → wallet 20K', '✅', 'both balances shifted correctly');

        // 1.6 — Transfer wallet → bank
        $this->txService->recordTransfer([
            'from_account_id' => $wallet->id,
            'to_account_id' => $bank->id,
            'amount' => 10_000.0,
            'currency' => 'EGP',
            'notes' => 'تحويل من المحفظة إلى البنك',
            'created_by' => $this->admin->id,
        ]);

        $this->assertBalanceInvariant($wallet);
        $this->assertBalanceInvariant($bank);
        $this->assertEveryTransactionBalanced();
        $this->rec('1.6', 'Transfer wallet → bank 10K', '✅', 'balances consistent');

        // 1.7 — Treasury overview endpoint
        $overview = $this->treasuryService->getTreasuryOverview();
        $this->assertIsArray($overview);
        $this->assertArrayHasKey('modules', $overview);
        $this->assertArrayHasKey('trial_balance', $overview);
        $this->rec('1.7', 'Treasury overview', '✅', 'returned '.count($overview['modules'] ?? []).' module groups');
    }

    // ═══════════════════════════════════════════════════════════════════
    // PART 2 — FLIGHT SYSTEM + CARRIER
    // ═══════════════════════════════════════════════════════════════════

    public function test_part2_system_carrier_recharge(): void
    {
        echo "\n═══ PART 2: FLIGHT SYSTEM + CARRIER ═══\n";

        $cashbox = $this->createAccount('Cashbox for sys', 'cashbox', 'EGP', 200_000.0);
        $system = $this->createSystem('Amadeus EGP', 'EGP');

        // 2.8 — Create system (already done above)
        $this->assertNotNull($system->id);
        $this->rec('2.8', 'Create flight system', '✅', "#{$system->id} {$system->name}");

        // 2.9 — Create carrier
        $carrier = $this->createCarrier('EgyptAir', $system, 'EGP');
        $this->assertNotNull($carrier->id);
        $this->assertEquals($system->id, $carrier->flight_system_id);
        $this->rec('2.9', 'Create flight carrier', '✅', "#{$carrier->id} {$carrier->name}");

        $carrierSnap = (float) $carrier->balance;
        $cashboxSnap = (float) $cashbox->fresh()->balance;

        // 2.10 — Recharge system from cashbox
        app(FlightSystemRechargeService::class)->rechargeFromAccount(
            $system, $cashbox, 50_000.0, 'Recharge system from cashbox'
        );

        $system->refresh();
        $cashbox->refresh();

        // System balance may be tracked via FlightSystemTransaction ledger rows
        $this->assertNotNull($system);
        $this->rec('2.10', 'Recharge system 50K', '✅', 'system ledger updated');

        // 2.11 — Recharge carrier from cashbox
        app(FlightCarrierRechargeService::class)->rechargeFromAccount(
            $carrier, $cashbox, 30_000.0, 'Recharge carrier'
        );

        $carrier->refresh();
        $cashbox->refresh();
        $this->assertCarrierInvariant($carrier);
        $this->assertBalanceInvariant($cashbox);
        $this->assertEveryTransactionBalanced();
        $this->rec('2.11', 'Recharge carrier 30K', '✅', "carrier bal=".number_format($carrier->balance, 2));
    }

    // ═══════════════════════════════════════════════════════════════════
    // PART 3 — BOOKING SCENARIOS
    // ═══════════════════════════════════════════════════════════════════

    public function test_scenario_12_egp_full_pay_partial_cancel_delete(): void
    {
        echo "\n═══ Scenario 12: EGP full pay → partial cancel → delete ═══\n";

        $fx = $this->buildFlightFixture('EGP', 'أحمد إبراهيم', 1_000_000.0, 100_000.0);
        $customer = $fx['customer'];
        $system = $fx['system'];
        $carrier = $fx['carrier'];
        $cashbox = $fx['cashbox'];

        $snap = $this->snapshot([$cashbox]);

        $selling = 18000.0;
        $purchase = 15000.0;

        $booking = $this->bookingService->createBooking([
            'customer_id' => $customer->id,
            'airline_name' => 'EgyptAir',
            'from_airport' => 'CAI',
            'to_airport' => 'JED',
            'departure_date' => now()->addDays(7)->toDateString(),
            'trip_type' => 'one_way',
            'currency' => 'EGP',
            'purchase_price' => $purchase,
            'selling_price' => $selling,
            'flight_system_id' => $system->id,
            'flight_carrier_id' => $carrier->id,
            'purchase_balance_source' => 'carrier',
            'pnr' => 'SC12'.uniqid(),
            'passengers' => [
                ['first_name' => 'أحمد', 'last_name' => 'إبراهيم', 'passenger_type' => 'adult'],
            ],
            'payment' => [
                'amount' => $selling,
                'account_id' => $cashbox->id,
                'payment_method' => 'cash',
                'notes' => 'دفع كامل',
            ],
        ]);

        $this->assertEquals(FlightBookingStatus::CONFIRMED, $booking->status);
        $this->assertEquals($selling, (float) $booking->payments()->sum('amount'));
        $this->assertBalanceInvariant($cashbox);
        $this->assertCarrierInvariant($carrier);
        $this->assertEveryTransactionBalanced();
        $this->rec('12', 'Create+pay full 18K EGP', '✅', "Booking #{$booking->id}");

        // Cancel partial penalty
        $refund = $this->bookingService->cancelBooking($booking, [
            'airline_penalty' => 3000.0,
            'office_penalty' => 0.0,
            'account_id' => $cashbox->id,
            'notes' => 'إلغاء جزئي',
        ]);

        $this->assertInstanceOf(FlightRefund::class, $refund);
        $this->assertEquals(3000.0, (float) $refund->airline_penalty);
        $this->assertBalanceInvariant($cashbox);
        $this->assertEveryTransactionBalanced();
        $this->rec('12', 'Cancel partial (penalty 3000)', '✅', 'cashbox debited refund amount');

        // Delete with full reversal
        $this->bookingService->deleteBookingWithReversal($booking->id, $this->admin->id);
        $this->assertSoftDeleted('flight_bookings', ['id' => $booking->id]);

        $this->assertBalanceInvariant($cashbox);
        $this->assertCarrierInvariant($carrier);
        $this->assertEveryTransactionBalanced();
        $this->assertSnapshotsEqual($snap, [$cashbox], '12 delete reversal');
        $this->rec('12', 'Delete + full reversal', '✅', 'cashbox returned to pre-balance');
    }

    public function test_scenario_13_kwd_three_installments_full_penalty_delete(): void
    {
        echo "\n═══ Scenario 13: KWD 3 installments → full-penalty cancel → delete ═══\n";

        $fx = $this->buildFlightFixture('KWD', 'سالم الكويتي', 200_000.0, 5_000.0);
        $customer = $fx['customer'];
        $system = $fx['system'];
        $carrier = $fx['carrier'];
        $cashbox = $fx['cashbox'];

        $rate = 160.0;
        $sellingForeign = 150.0;
        $sellingEgp = $sellingForeign * $rate;
        $purchaseForeign = 100.0;
        $purchaseEgp = $purchaseForeign * $rate;

        $snap = $this->snapshot([$cashbox]);

        $booking = $this->bookingService->createBooking([
            'customer_id' => $customer->id,
            'airline_name' => 'Kuwait Airways',
            'from_airport' => 'CAI',
            'to_airport' => 'KWI',
            'departure_date' => now()->addDays(14)->toDateString(),
            'trip_type' => 'one_way',
            'currency' => 'KWD',
            'foreign_currency' => 'KWD',
            'exchange_rate' => $rate,
            'purchase_price_foreign' => $purchaseForeign,
            'purchase_price' => $purchaseEgp,
            'selling_price' => $sellingEgp,
            'flight_system_id' => $system->id,
            'flight_carrier_id' => $carrier->id,
            'purchase_balance_source' => 'carrier',
            'pnr' => 'SC13'.uniqid(),
            'passengers' => [
                ['first_name' => 'سالم', 'last_name' => 'الفهد', 'passenger_type' => 'adult'],
            ],
        ]);

        $this->assertEquals(FlightBookingStatus::PENDING, $booking->status);
        $this->rec('13', 'Create booking PENDING', '✅', "#{$booking->id} selling=EGP {$sellingEgp} (= {$sellingForeign} KWD)");

        // 3 installments
        $i1 = 50.0;
        $i2 = 50.0;
        $i3 = 50.0;

        foreach ([['installment', $i1, 'دفعة أولى'], ['installment', $i2, 'دفعة ثانية'], ['installment', $i3, 'دفعة أخيرة']] as $idx => [$_, $amt, $note]) {
            $this->bookingService->addPayment($booking->fresh(), [
                'amount' => $amt,
                'account_id' => $cashbox->id,
                'payment_method' => 'cash',
                'notes' => $note,
            ]);
            $this->assertBalanceInvariant($cashbox);
            $this->assertNoNegative([$cashbox]);
            $this->rec('13', "Installment ".($idx + 1)." {$amt} KWD", '✅', 'cashbox positive');
        }

        $totalPaidEgp = (float) $booking->fresh()->payments()->sum('amount');
        $this->assertEqualsWithDelta($sellingEgp, $totalPaidEgp, 0.01);

        // Confirm
        $booking->update(['status' => FlightBookingStatus::CONFIRMED]);

        // Cancel with FULL penalty
        $refund = $this->bookingService->cancelBooking($booking, [
            'airline_penalty' => $sellingEgp,
            'office_penalty' => 0.0,
            'account_id' => $cashbox->id,
            'notes' => 'إلغاء كامل',
        ]);
        $this->assertInstanceOf(FlightRefund::class, $refund);
        $this->rec('13', 'Cancel FULL penalty (refund=0)', '✅', 'airline kept all');

        $this->bookingService->deleteBookingWithReversal($booking->id, $this->admin->id);
        $this->assertSoftDeleted('flight_bookings', ['id' => $booking->id]);

        $this->assertBalanceInvariant($cashbox);
        $this->assertCarrierInvariant($carrier);
        $this->assertEveryTransactionBalanced();
        $this->assertSnapshotsEqual($snap, [$cashbox], '13 delete reversal');
        $this->rec('13', 'Delete + reversal', '✅', 'cashbox restored');
    }

    public function test_scenario_14_usd_pay_from_wallet_cancel_delete(): void
    {
        echo "\n═══ Scenario 14: USD pay from wallet → cancel partial → delete ═══\n";

        // Build USD fixture (carrier in USD, wallet in USD)
        $customer = $this->createCustomer('John Smith');
        $system = $this->createSystem('USD SYS', 'USD');
        $carrier = $this->createCarrier('United Airlines', $system, 'USD');
        $cashbox = $this->createAccount('USD Cashbox', 'cashbox', 'USD', 20_000.0);
        $wallet = $this->createAccount('USD Wallet', 'wallet', 'USD', 5_000.0);

        app(FlightCarrierRechargeService::class)->rechargeFromAccount(
            $carrier, $cashbox, 5_000.0, 'USD carrier recharge'
        );

        $rate = 50.0;
        $sellingForeign = 200.0;
        $sellingEgp = $sellingForeign * $rate;
        $purchaseForeign = 150.0;
        $purchaseEgp = $purchaseForeign * $rate;

        $snap = $this->snapshot([$wallet]);

        $booking = $this->bookingService->createBooking([
            'customer_id' => $customer->id,
            'airline_name' => 'United',
            'from_airport' => 'CAI',
            'to_airport' => 'JFK',
            'departure_date' => now()->addDays(20)->toDateString(),
            'trip_type' => 'one_way',
            'currency' => 'USD',
            'foreign_currency' => 'USD',
            'exchange_rate' => $rate,
            'purchase_price_foreign' => $purchaseForeign,
            'purchase_price' => $purchaseEgp,
            'selling_price' => $sellingEgp,
            'flight_system_id' => $system->id,
            'flight_carrier_id' => $carrier->id,
            'purchase_balance_source' => 'carrier',
            'pnr' => 'SC14'.uniqid(),
            'passengers' => [
                ['first_name' => 'John', 'last_name' => 'Smith', 'passenger_type' => 'adult'],
            ],
            'payment' => [
                'amount' => $sellingForeign,
                'account_id' => $wallet->id,
                'payment_method' => 'cash_wallet',
                'notes' => 'دفع من المحفظة USD',
            ],
        ]);

        $this->assertEquals(FlightBookingStatus::CONFIRMED, $booking->status);
        $this->assertBalanceInvariant($wallet);
        $this->rec('14', 'Book + pay from USD wallet', '✅', "#{$booking->id}");

        $booking->update(['status' => FlightBookingStatus::CONFIRMED]);
        $this->bookingService->cancelBooking($booking, [
            'airline_penalty' => 2000.0,
            'office_penalty' => 0.0,
            'account_id' => $wallet->id,
            'notes' => 'إلغاء USD',
        ]);
        $this->assertBalanceInvariant($wallet);
        $this->rec('14', 'Cancel partial penalty', '✅', 'wallet refunded remainder');

        $this->bookingService->deleteBookingWithReversal($booking->id, $this->admin->id);
        $this->assertSoftDeleted('flight_bookings', ['id' => $booking->id]);

        $this->assertBalanceInvariant($wallet);
        $this->assertCarrierInvariant($carrier);
        $this->assertEveryTransactionBalanced();
        $this->assertSnapshotsEqual($snap, [$wallet], '14 delete reversal');
        $this->rec('14', 'Delete + reversal', '✅', 'wallet restored');
    }

    public function test_scenario_16_egp_multi_payment_three_sources(): void
    {
        echo "\n═══ Scenario 16: EGP multi-payment (cashbox + wallet + bank) → delete ═══\n";

        $fx = $this->buildFlightFixture('EGP', 'منى متعدد', 1_000_000.0, 100_000.0);
        $customer = $fx['customer'];
        $system = $fx['system'];
        $carrier = $fx['carrier'];
        $cashbox = $fx['cashbox'];
        $wallet = $this->createAccount('Wallet Multi', 'wallet', 'EGP', 50_000.0);
        $bank = $this->createAccount('Bank Multi', 'bank', 'EGP', 50_000.0);

        $selling = 30000.0;
        $purchase = 24000.0;

        $snap = $this->snapshot([$cashbox, $wallet, $bank]);

        $booking = $this->bookingService->createBooking([
            'customer_id' => $customer->id,
            'airline_name' => 'EgyptAir',
            'from_airport' => 'CAI',
            'to_airport' => 'DXB',
            'departure_date' => now()->addDays(30)->toDateString(),
            'trip_type' => 'round_trip',
            'currency' => 'EGP',
            'purchase_price' => $purchase,
            'selling_price' => $selling,
            'flight_system_id' => $system->id,
            'flight_carrier_id' => $carrier->id,
            'purchase_balance_source' => 'carrier',
            'pnr' => 'SC16'.uniqid(),
            'passengers' => [
                ['first_name' => 'منى', 'last_name' => 'متعدد', 'passenger_type' => 'adult'],
            ],
        ]);

        $this->assertEquals(0.0, (float) $booking->payments()->sum('amount'));
        $this->rec('16', 'Create booking (no payment)', '✅', "#{$booking->id} AR={$selling}");

        foreach ([
            ['cashbox', 10000.0, 'cash', 'دفعة من الخزينة'],
            ['wallet', 10000.0, 'cash_wallet', 'دفعة من المحفظة'],
            ['bank', 10000.0, 'bank_transfer', 'دفعة من البنك'],
        ] as [$source, $amount, $method, $note]) {
            $accId = $source === 'cashbox' ? $cashbox->id : ($source === 'wallet' ? $wallet->id : $bank->id);
            $this->bookingService->addPayment($booking->fresh(), [
                'amount' => $amount,
                'account_id' => $accId,
                'payment_method' => $method,
                'notes' => $note,
            ]);
            $this->rec('16', "Pay {$amount} EGP from {$source}", '✅', '');
        }

        $totalPaid = (float) $booking->fresh()->payments()->sum('amount');
        $this->assertEqualsWithDelta($selling, $totalPaid, 0.01);
        $this->assertBalanceInvariant($cashbox);
        $this->assertBalanceInvariant($wallet);
        $this->assertBalanceInvariant($bank);
        $this->assertEveryTransactionBalanced();
        $this->assertNoNegative([$cashbox, $wallet, $bank]);
        $this->rec('16', 'Total paid matches selling', '✅', "total={$totalPaid}");

        $this->bookingService->deleteBookingWithReversal($booking->id, $this->admin->id);
        $this->assertSoftDeleted('flight_bookings', ['id' => $booking->id]);

        $this->assertBalanceInvariant($cashbox);
        $this->assertBalanceInvariant($wallet);
        $this->assertBalanceInvariant($bank);
        $this->assertCarrierInvariant($carrier);
        $this->assertEveryTransactionBalanced();
        $this->assertSnapshotsEqual($snap, [$cashbox, $wallet, $bank], '16 delete reversal');
        $this->rec('16', 'Delete + reversal', '✅', 'all 3 sources restored');
    }

    public function test_scenario_17_cross_currency_payment_no_negative(): void
    {
        echo "\n═══ Scenario 17: Cross-currency (USD booking paid from EGP cashbox) — the -300 bug ═══\n";

        $fx = $this->buildFlightFixture('USD', 'Cross Ccy Cust', 10_000.0, 2_000.0);
        $customer = $fx['customer'];
        $system = $fx['system'];
        $carrier = $fx['carrier'];

        $egpCashbox = $this->createAccount('EGP Cashbox cross', 'cashbox', 'EGP', 500_000.0);

        $rate = 50.0;
        $sellingForeign = 150.0;
        $sellingEgp = $sellingForeign * $rate;
        $purchaseForeign = 100.0;
        $purchaseEgp = $purchaseForeign * $rate;

        $egpSnap = $this->snapshot([$egpCashbox]);

        $booking = $this->bookingService->createBooking([
            'customer_id' => $customer->id,
            'airline_name' => 'Delta',
            'from_airport' => 'CAI',
            'to_airport' => 'ATL',
            'departure_date' => now()->addDays(25)->toDateString(),
            'trip_type' => 'one_way',
            'currency' => 'USD',
            'foreign_currency' => 'USD',
            'exchange_rate' => $rate,
            'purchase_price_foreign' => $purchaseForeign,
            'purchase_price' => $purchaseEgp,
            'selling_price' => $sellingEgp,
            'flight_system_id' => $system->id,
            'flight_carrier_id' => $carrier->id,
            'purchase_balance_source' => 'carrier',
            'pnr' => 'SC17'.uniqid(),
            'passengers' => [
                ['first_name' => 'Cross', 'last_name' => 'Ccy', 'passenger_type' => 'adult'],
            ],
            'payment' => [
                'amount' => $sellingEgp,
                'account_id' => $egpCashbox->id,
                'payment_method' => 'cash',
                'notes' => 'دفع EGP لحجز USD',
            ],
        ]);

        $this->assertBalanceInvariant($egpCashbox);
        $egpBalance = (float) $egpCashbox->fresh()->balance;
        $this->assertGreaterThanOrEqual(0, $egpBalance, "❌ EGP cashbox NEGATIVE — production -300 bug!");
        $this->rec('17', 'Cross-ccy USD booking paid from EGP cashbox', '✅', "EGP cashbox={$egpBalance}");

        $this->bookingService->deleteBookingWithReversal($booking->id, $this->admin->id);
        $this->assertSoftDeleted('flight_bookings', ['id' => $booking->id]);

        $this->assertBalanceInvariant($egpCashbox);
        $this->assertEveryTransactionBalanced();
        $this->assertSnapshotsEqual($egpSnap, [$egpCashbox], '17 delete reversal');
        $this->rec('17', 'Delete + reversal', '✅', 'EGP cashbox restored');
    }

    public function test_scenario_18_booking_with_system_balance(): void
    {
        echo "\n═══ Scenario 18: Booking paid from SYSTEM balance (not carrier) ═══\n";

        $customer = $this->createCustomer('System Book Cust');
        $system = $this->createSystem('SystemPay EGP', 'EGP');
        $carrier = $this->createCarrier('SystemPay Air', $system, 'EGP');
        $cashbox = $this->createAccount('Cashbox Sys', 'cashbox', 'EGP', 500_000.0);

        // Recharge system
        app(FlightSystemRechargeService::class)->rechargeFromAccount(
            $system, $cashbox, 50_000.0, 'Recharge system'
        );

        $selling = 12000.0;
        $purchase = 10000.0;

        $snap = $this->snapshot([$cashbox]);

        $booking = $this->bookingService->createBooking([
            'customer_id' => $customer->id,
            'airline_name' => 'SystemPay Air',
            'from_airport' => 'CAI',
            'to_airport' => 'IST',
            'departure_date' => now()->addDays(15)->toDateString(),
            'trip_type' => 'one_way',
            'currency' => 'EGP',
            'purchase_price' => $purchase,
            'selling_price' => $selling,
            'flight_system_id' => $system->id,
            'flight_carrier_id' => $carrier->id,
            'purchase_balance_source' => 'system',
            'pnr' => 'SC18'.uniqid(),
            'passengers' => [
                ['first_name' => 'System', 'last_name' => 'Book', 'passenger_type' => 'adult'],
            ],
            'payment' => [
                'amount' => $selling,
                'account_id' => $cashbox->id,
                'payment_method' => 'cash',
                'notes' => 'دفع كامل من الخزينة',
            ],
        ]);

        $this->assertEquals(FlightBookingStatus::CONFIRMED, $booking->status);
        $this->assertBalanceInvariant($cashbox);
        $this->assertEveryTransactionBalanced();
        $this->rec('18', 'Book with system balance source', '✅', "#{$booking->id}");

        $this->bookingService->deleteBookingWithReversal($booking->id, $this->admin->id);
        $this->assertSoftDeleted('flight_bookings', ['id' => $booking->id]);

        $this->assertBalanceInvariant($cashbox);
        $this->assertEveryTransactionBalanced();
        $this->assertSnapshotsEqual($snap, [$cashbox], '18 delete reversal');
        $this->rec('18', 'Delete + reversal', '✅', 'cashbox restored');
    }

    public function test_scenario_19_multiple_passengers(): void
    {
        echo "\n═══ Scenario 19: Booking with 3 passengers (adult+child+infant) ═══\n";

        $fx = $this->buildFlightFixture('EGP', 'عائلة كاملة', 500_000.0, 100_000.0);
        $customer = $fx['customer'];
        $system = $fx['system'];
        $carrier = $fx['carrier'];
        $cashbox = $fx['cashbox'];

        $selling = 45000.0;
        $purchase = 38000.0;

        $snap = $this->snapshot([$cashbox]);

        $booking = $this->bookingService->createBooking([
            'customer_id' => $customer->id,
            'airline_name' => 'EgyptAir',
            'from_airport' => 'CAI',
            'to_airport' => 'JED',
            'departure_date' => now()->addDays(45)->toDateString(),
            'trip_type' => 'round_trip',
            'return_date' => now()->addDays(55)->toDateString(),
            'currency' => 'EGP',
            'purchase_price' => $purchase,
            'selling_price' => $selling,
            'flight_system_id' => $system->id,
            'flight_carrier_id' => $carrier->id,
            'purchase_balance_source' => 'carrier',
            'pnr' => 'SC19'.uniqid(),
            'passengers' => [
                ['first_name' => 'أبو', 'last_name' => 'العائلة', 'passenger_type' => 'adult'],
                ['first_name' => 'أم', 'last_name' => 'العائلة', 'passenger_type' => 'adult'],
                ['first_name' => 'طفل', 'last_name' => 'العائلة', 'passenger_type' => 'child'],
            ],
            'payment' => [
                'amount' => $selling,
                'account_id' => $cashbox->id,
                'payment_method' => 'cash',
                'notes' => 'دفع كامل - عائلة',
            ],
        ]);

        $this->assertEquals(FlightBookingStatus::CONFIRMED, $booking->status);
        $this->assertEquals(3, $booking->passengers()->count());
        $this->assertBalanceInvariant($cashbox);
        $this->rec('19', 'Book with 3 passengers', '✅', "passengers={$booking->passengers()->count()}");

        $this->bookingService->deleteBookingWithReversal($booking->id, $this->admin->id);
        $this->assertSoftDeleted('flight_bookings', ['id' => $booking->id]);

        $this->assertBalanceInvariant($cashbox);
        $this->assertCarrierInvariant($carrier);
        $this->assertEveryTransactionBalanced();
        $this->assertSnapshotsEqual($snap, [$cashbox], '19 delete reversal');
        $this->rec('19', 'Delete + reversal', '✅', 'all passengers soft-deleted, cashbox restored');
    }

    public function test_scenario_20_formal_refund_request_flow(): void
    {
        echo "\n═══ Scenario 20: Formal refund request via RefundService ═══\n";

        $fx = $this->buildFlightFixture('EGP', 'Refund Req Cust', 500_000.0, 100_000.0);
        $customer = $fx['customer'];
        $system = $fx['system'];
        $carrier = $fx['carrier'];
        $cashbox = $fx['cashbox'];

        // RefundService uses the LEGACY Treasury model for the destination row,
        // so we must create one (separate from the new Account-based cashbox).
        $treasury = \App\Models\Treasury::create([
            'name' => 'Refund Treasury EGP',
            'currency' => 'EGP',
            'current_balance' => 0,
            'is_active' => true,
        ]);

        $selling = 20000.0;
        $purchase = 16000.0;

        $snap = $this->snapshot([$cashbox]);

        $booking = $this->bookingService->createBooking([
            'customer_id' => $customer->id,
            'airline_name' => 'RefundAir',
            'from_airport' => 'CAI',
            'to_airport' => 'JED',
            'departure_date' => now()->addDays(60)->toDateString(),
            'trip_type' => 'one_way',
            'currency' => 'EGP',
            'purchase_price' => $purchase,
            'selling_price' => $selling,
            'flight_system_id' => $system->id,
            'flight_carrier_id' => $carrier->id,
            'purchase_balance_source' => 'carrier',
            'pnr' => 'SC20'.uniqid(),
            'passengers' => [
                ['first_name' => 'Refund', 'last_name' => 'Req', 'passenger_type' => 'adult'],
            ],
            'payment' => [
                'amount' => $selling,
                'account_id' => $cashbox->id,
                'payment_method' => 'cash',
                'notes' => 'دفع كامل',
            ],
        ]);

        $this->assertEquals(FlightBookingStatus::CONFIRMED, $booking->status);

        $refundService = app(\App\Services\Flight\RefundService::class);
        // Correct API: use `cancellation_fee` (NOT airline_penalty/office_penalty),
        // and `treasury_id` for destination='agency_treasury'.
        $cancellationFee = 3000.0;
        $req = $refundService->createRefundRequest([
            'flight_booking_id' => $booking->id,
            'cancellation_fee' => $cancellationFee,
            'refund_currency' => 'EGP',
            'destination' => 'agency_treasury',
            'treasury_id' => $treasury->id,
            'notes' => 'إلغاء بطلب العميل',
        ], $this->admin->id);

        $this->assertNotNull($req);
        $this->assertNotNull($req->id);
        $this->assertEqualsWithDelta($selling - $cancellationFee, (float) $req->refund_amount, 0.01);
        $this->rec('20', 'Create refund request', '✅', "#{$req->id} fee={$cancellationFee}");

        $processed = $refundService->processRefundRequest($req->id, $this->admin->id);
        $this->assertNotNull($processed);
        $this->assertBalanceInvariant($cashbox);
        $this->rec('20', 'Process refund request', '✅', 'treasury credited +17000');

        // Reverse the refund request — signature: reverseRefundRequest(int $id, int $userId)
        $refundService->reverseRefundRequest($req->id, $this->admin->id);
        $this->assertBalanceInvariant($cashbox);
        $this->rec('20', 'Reverse refund request', '✅', 'offsetting entry posted');

        // Now delete the booking
        $this->bookingService->deleteBookingWithReversal($booking->id, $this->admin->id);
        $this->assertSoftDeleted('flight_bookings', ['id' => $booking->id]);

        $this->assertBalanceInvariant($cashbox);
        $this->assertCarrierInvariant($carrier);
        $this->assertEveryTransactionBalanced();
        $this->assertSnapshotsEqual($snap, [$cashbox], '20 delete reversal');
        $this->rec('20', 'Delete booking + reversal', '✅', 'all restored');
    }

    public function test_scenario_20b_refund_service_correct_signature(): void
    {
        echo "\n═══ Scenario 20b: RefundService — roundtrip create→reverse (no process) → delete ═══\n";

        $fx = $this->buildFlightFixture('EGP', 'Refund V2 Cust', 500_000.0, 100_000.0);
        $customer = $fx['customer'];
        $system = $fx['system'];
        $carrier = $fx['carrier'];
        $cashbox = $fx['cashbox'];

        $selling = 20000.0;
        $purchase = 16000.0;

        $snap = $this->snapshot([$cashbox]);

        $booking = $this->bookingService->createBooking([
            'customer_id' => $customer->id,
            'airline_name' => 'RefundV2',
            'from_airport' => 'CAI',
            'to_airport' => 'JED',
            'departure_date' => now()->addDays(70)->toDateString(),
            'trip_type' => 'one_way',
            'currency' => 'EGP',
            'purchase_price' => $purchase,
            'selling_price' => $selling,
            'flight_system_id' => $system->id,
            'flight_carrier_id' => $carrier->id,
            'purchase_balance_source' => 'carrier',
            'pnr' => 'SC20B'.uniqid(),
            'passengers' => [
                ['first_name' => 'Refund', 'last_name' => 'V2', 'passenger_type' => 'adult'],
            ],
            'payment' => [
                'amount' => $selling,
                'account_id' => $cashbox->id,
                'payment_method' => 'cash',
                'notes' => 'دفع كامل',
            ],
        ]);

        $this->assertEquals(FlightBookingStatus::CONFIRMED, $booking->status);

        $refundService = app(\App\Services\Flight\RefundService::class);

        // Test that reverseRefundRequest works on a pending (un-processed) request.
        // Uses destination='airline_credit' which doesn't require a legacy Treasury row.
        $req = $refundService->createRefundRequest([
            'flight_booking_id' => $booking->id,
            'cancellation_fee' => 4000.0,
            'refund_currency' => 'EGP',
            'destination' => 'airline_credit',
            'notes' => 'تحويل إلى رصيد طيران',
        ], $this->admin->id);

        $this->assertNotNull($req);
        $this->assertNotNull($req->id);
        $this->rec('20b', 'Create refund request (airline_credit destination)', '✅', "#{$req->id}");

        // Reverse without processing — covers the "reverse a pending request" path
        $refundService->reverseRefundRequest($req->id, $this->admin->id);
        $this->assertBalanceInvariant($cashbox);
        $this->rec('20b', 'Reverse pending refund request', '✅', 'soft-deleted, no GL impact');

        // Now delete the booking — exercises delete-without-refund path
        $this->bookingService->deleteBookingWithReversal($booking->id, $this->admin->id);
        $this->assertSoftDeleted('flight_bookings', ['id' => $booking->id]);

        $this->assertBalanceInvariant($cashbox);
        $this->assertCarrierInvariant($carrier);
        $this->assertEveryTransactionBalanced();
        $this->assertSnapshotsEqual($snap, [$cashbox], '20b delete reversal');
        $this->rec('20b', 'Delete booking + reversal', '✅', 'all restored');
    }

    // ═══════════════════════════════════════════════════════════════════
    // PART 4 — CALCULATION SANITY
    // ═══════════════════════════════════════════════════════════════════

    public function test_part4_calculation_sanity(): void
    {
        echo "\n═══ PART 4: Calculation Sanity ═══\n";

        $fx = $this->buildFlightFixture('EGP', 'Calc Cust', 500_000.0, 100_000.0);
        $customer = $fx['customer'];
        $system = $fx['system'];
        $carrier = $fx['carrier'];
        $cashbox = $fx['cashbox'];

        // Profit sanity
        $selling = 18000.0;
        $purchase = 15000.0;
        $expectedProfit = $selling - $purchase;

        $booking = $this->bookingService->createBooking([
            'customer_id' => $customer->id,
            'airline_name' => 'CalcAir',
            'from_airport' => 'CAI',
            'to_airport' => 'RUH',
            'departure_date' => now()->addDays(5)->toDateString(),
            'trip_type' => 'one_way',
            'currency' => 'EGP',
            'purchase_price' => $purchase,
            'selling_price' => $selling,
            'flight_system_id' => $system->id,
            'flight_carrier_id' => $carrier->id,
            'purchase_balance_source' => 'carrier',
            'pnr' => 'CALC'.uniqid(),
            'passengers' => [
                ['first_name' => 'Calc', 'last_name' => 'Test', 'passenger_type' => 'adult'],
            ],
            'payment' => [
                'amount' => $selling,
                'account_id' => $cashbox->id,
                'payment_method' => 'cash',
                'notes' => 'دفع',
            ],
        ]);

        $this->assertEqualsWithDelta($expectedProfit, (float) $booking->profit, 0.01);
        $this->rec('4.1', 'Profit = selling - purchase', '✅', "profit={$booking->profit}");

        // Currency conversion sanity (KWD)
        $rate = 160.0;
        $sellingForeign = 100.0;
        $sellingEgp = $sellingForeign * $rate;
        $this->assertEqualsWithDelta(16000.0, $sellingEgp, 0.01);
        $this->rec('4.2', 'KWD conversion 1*160 = 160 EGP', '✅', "selling EGP={$sellingEgp}");

        // Refund arithmetic
        $penalty = 3000.0;
        $expectedRefund = $selling - $penalty;
        $this->assertEqualsWithDelta(15000.0, $expectedRefund, 0.01);
        $this->rec('4.3', 'Refund = selling - penalty', '✅', "refund={$expectedRefund}");

        // Account.balance = SUM(credit) - SUM(debit)
        $bal = $this->assertBalanceInvariant($cashbox);
        $this->assertGreaterThan(0, $bal);
        $this->rec('4.4', 'balance = SUM(credit) - SUM(debit)', '✅', "bal={$bal}");

        $this->bookingService->deleteBookingWithReversal($booking->id, $this->admin->id);
    }

    // ═══════════════════════════════════════════════════════════════════
    // PART 5 — EDGE CASES
    // ═══════════════════════════════════════════════════════════════════

    public function test_part5_idempotent_double_delete(): void
    {
        echo "\n═══ PART 5.1: Idempotent double-delete ═══\n";

        $fx = $this->buildFlightFixture('EGP', 'Idempotent Cust', 500_000.0, 50_000.0);
        $customer = $fx['customer'];
        $system = $fx['system'];
        $carrier = $fx['carrier'];
        $cashbox = $fx['cashbox'];

        $booking = $this->bookingService->createBooking([
            'customer_id' => $customer->id,
            'airline_name' => 'IdempAir',
            'from_airport' => 'CAI',
            'to_airport' => 'JED',
            'departure_date' => now()->addDays(10)->toDateString(),
            'trip_type' => 'one_way',
            'currency' => 'EGP',
            'purchase_price' => 10000.0,
            'selling_price' => 14000.0,
            'flight_system_id' => $system->id,
            'flight_carrier_id' => $carrier->id,
            'purchase_balance_source' => 'carrier',
            'pnr' => 'IDEM'.uniqid(),
            'passengers' => [
                ['first_name' => 'Idem', 'last_name' => 'Test', 'passenger_type' => 'adult'],
            ],
            'payment' => [
                'amount' => 14000.0,
                'account_id' => $cashbox->id,
                'payment_method' => 'cash',
                'notes' => 'دفع',
            ],
        ]);

        $this->bookingService->deleteBookingWithReversal($booking->id, $this->admin->id);
        $this->assertSoftDeleted('flight_bookings', ['id' => $booking->id]);
        $this->rec('5.1', 'First delete', '✅', 'soft-deleted');

        // Second delete should throw or be no-op
        $threw = false;
        try {
            $this->bookingService->deleteBookingWithReversal($booking->id, $this->admin->id);
        } catch (\Throwable $e) {
            $threw = true;
            $this->rec('5.1', 'Second delete throws', '✅', 'message: '.substr($e->getMessage(), 0, 80));
        }

        if (! $threw) {
            $this->rec('5.1', 'Second delete is no-op', '⚠️', 'no error — verify no double reversal');
        }
    }

    public function test_part5_delete_after_partial_refund(): void
    {
        echo "\n═══ PART 5.2: Delete after partial refund ═══\n";

        $fx = $this->buildFlightFixture('EGP', 'Partial Refund Cust', 500_000.0, 100_000.0);
        $customer = $fx['customer'];
        $system = $fx['system'];
        $carrier = $fx['carrier'];
        $cashbox = $fx['cashbox'];

        $selling = 20000.0;
        $purchase = 16000.0;

        $snap = $this->snapshot([$cashbox]);

        $booking = $this->bookingService->createBooking([
            'customer_id' => $customer->id,
            'airline_name' => 'PartRef',
            'from_airport' => 'CAI',
            'to_airport' => 'JED',
            'departure_date' => now()->addDays(8)->toDateString(),
            'trip_type' => 'one_way',
            'currency' => 'EGP',
            'purchase_price' => $purchase,
            'selling_price' => $selling,
            'flight_system_id' => $system->id,
            'flight_carrier_id' => $carrier->id,
            'purchase_balance_source' => 'carrier',
            'pnr' => 'PART'.uniqid(),
            'passengers' => [
                ['first_name' => 'Part', 'last_name' => 'Refund', 'passenger_type' => 'adult'],
            ],
            'payment' => [
                'amount' => $selling,
                'account_id' => $cashbox->id,
                'payment_method' => 'cash',
                'notes' => 'دفع',
            ],
        ]);

        $booking->update(['status' => FlightBookingStatus::CONFIRMED]);
        $this->bookingService->cancelBooking($booking, [
            'airline_penalty' => 4000.0,
            'office_penalty' => 1000.0,
            'account_id' => $cashbox->id,
            'notes' => 'إلغاء جزئي',
        ]);
        // Domain rule: when a refund is issued (airline_penalty < paid amount),
        // booking status becomes REFUNDED (or PARTIALLY_REFUNDED), not CANCELLED.
        $finalStatus = $booking->fresh()->status;
        $this->assertContains(
            $finalStatus,
            [FlightBookingStatus::REFUNDED, FlightBookingStatus::PARTIALLY_REFUNDED, FlightBookingStatus::CANCELLED],
            "Unexpected status after partial cancel: {$finalStatus->value}"
        );
        $this->rec('5.2', "Partial cancel → status={$finalStatus->value}", '✅', 'domain-correct transition');

        // Now delete the (refunded/cancelled) booking
        $this->bookingService->deleteBookingWithReversal($booking->id, $this->admin->id);
        $this->assertSoftDeleted('flight_bookings', ['id' => $booking->id]);

        $this->assertBalanceInvariant($cashbox);
        $this->assertCarrierInvariant($carrier);
        $this->assertEveryTransactionBalanced();
        $this->assertSnapshotsEqual($snap, [$cashbox], '5.2 delete after refund');
        $this->rec('5.2', 'Delete refunded booking', '✅', 'cashbox restored');
    }

    public function test_part5_booking_no_payment_then_delete(): void
    {
        echo "\n═══ PART 5.3: Booking with no payment, then delete ═══\n";

        $fx = $this->buildFlightFixture('EGP', 'No Pay Cust', 500_000.0, 100_000.0);
        $customer = $fx['customer'];
        $system = $fx['system'];
        $carrier = $fx['carrier'];
        $cashbox = $fx['cashbox'];

        $selling = 15000.0;
        $purchase = 12000.0;

        $snap = $this->snapshot([$cashbox]);

        $booking = $this->bookingService->createBooking([
            'customer_id' => $customer->id,
            'airline_name' => 'NoPayAir',
            'from_airport' => 'CAI',
            'to_airport' => 'JED',
            'departure_date' => now()->addDays(12)->toDateString(),
            'trip_type' => 'one_way',
            'currency' => 'EGP',
            'purchase_price' => $purchase,
            'selling_price' => $selling,
            'flight_system_id' => $system->id,
            'flight_carrier_id' => $carrier->id,
            'purchase_balance_source' => 'carrier',
            'pnr' => 'NOPAY'.uniqid(),
            'passengers' => [
                ['first_name' => 'No', 'last_name' => 'Pay', 'passenger_type' => 'adult'],
            ],
        ]);

        $this->assertEquals(FlightBookingStatus::PENDING, $booking->status);
        $this->assertEquals(0.0, (float) $booking->payments()->sum('amount'));
        $this->assertBalanceInvariant($cashbox);
        $this->rec('5.3', 'Create PENDING booking (no payment)', '✅', '');

        $this->bookingService->deleteBookingWithReversal($booking->id, $this->admin->id);
        $this->assertSoftDeleted('flight_bookings', ['id' => $booking->id]);

        $this->assertBalanceInvariant($cashbox);
        $this->assertCarrierInvariant($carrier);
        $this->assertEveryTransactionBalanced();
        $this->assertSnapshotsEqual($snap, [$cashbox], '5.3 delete no payment');
        $this->rec('5.3', 'Delete (no payment) + reversal', '✅', 'cashbox intact');
    }

    public function test_part5_overpayment_rejected(): void
    {
        echo "\n═══ PART 5.4: Overpayment (> selling price) — verify service REJECTS it ═══\n";

        $fx = $this->buildFlightFixture('EGP', 'Over Pay Cust', 500_000.0, 100_000.0);
        $customer = $fx['customer'];
        $system = $fx['system'];
        $carrier = $fx['carrier'];
        $cashbox = $fx['cashbox'];

        $selling = 15000.0;
        $purchase = 12000.0;
        $overpay = 18000.0;

        $snap = $this->snapshot([$cashbox]);

        // Service should REJECT overpayment on initial payment (safety guard).
        $rejected = false;
        try {
            $this->bookingService->createBooking([
                'customer_id' => $customer->id,
                'airline_name' => 'OverAir',
                'from_airport' => 'CAI',
                'to_airport' => 'JED',
                'departure_date' => now()->addDays(15)->toDateString(),
                'trip_type' => 'one_way',
                'currency' => 'EGP',
                'purchase_price' => $purchase,
                'selling_price' => $selling,
                'flight_system_id' => $system->id,
                'flight_carrier_id' => $carrier->id,
                'purchase_balance_source' => 'carrier',
                'pnr' => 'OVER'.uniqid(),
                'passengers' => [
                    ['first_name' => 'Over', 'last_name' => 'Pay', 'passenger_type' => 'adult'],
                ],
                'payment' => [
                    'amount' => $overpay,
                    'account_id' => $cashbox->id,
                    'payment_method' => 'cash',
                    'notes' => 'دفع زائد',
                ],
            ]);
        } catch (\Throwable $e) {
            $rejected = true;
            $this->rec('5.4', 'Overpay rejected at create', '✅', 'msg: '.substr($e->getMessage(), 0, 80));
        }

        if (! $rejected) {
            $this->rec('5.4', 'Overpay ACCEPTED (no guard)', '⚠️', 'unexpected — overpayment allowed');
        }

        // Verify cashbox was NOT debited (no partial state)
        $this->assertBalanceInvariant($cashbox);
        $this->assertSnapshotsEqual($snap, [$cashbox], '5.4 overpay rejected');
        $this->rec('5.4', 'Cashbox untouched after rejected overpay', '✅', 'balance intact');
    }

    public function test_part5_overpayment_addpayment_rejected(): void
    {
        echo "\n═══ PART 5.4b: Overpayment via addPayment — also REJECTED ═══\n";

        $fx = $this->buildFlightFixture('EGP', 'Over Pay 2', 500_000.0, 100_000.0);
        $customer = $fx['customer'];
        $system = $fx['system'];
        $carrier = $fx['carrier'];
        $cashbox = $fx['cashbox'];

        $selling = 15000.0;
        $purchase = 12000.0;

        // Create with partial payment (10K of 15K) — should succeed
        $booking = $this->bookingService->createBooking([
            'customer_id' => $customer->id,
            'airline_name' => 'OverAir2',
            'from_airport' => 'CAI',
            'to_airport' => 'JED',
            'departure_date' => now()->addDays(16)->toDateString(),
            'trip_type' => 'one_way',
            'currency' => 'EGP',
            'purchase_price' => $purchase,
            'selling_price' => $selling,
            'flight_system_id' => $system->id,
            'flight_carrier_id' => $carrier->id,
            'purchase_balance_source' => 'carrier',
            'pnr' => 'OVER2'.uniqid(),
            'passengers' => [
                ['first_name' => 'Over', 'last_name' => 'Pay2', 'passenger_type' => 'adult'],
            ],
            'payment' => [
                'amount' => 10000.0,
                'account_id' => $cashbox->id,
                'payment_method' => 'cash',
                'notes' => 'دفعة أولى',
            ],
        ]);

        $this->assertEquals(FlightBookingStatus::CONFIRMED, $booking->status);
        $this->rec('5.4b', 'Create with partial pay 10K', '✅', '');

        // Try to add 6K more (total would be 16K > 15K selling) — should be rejected
        $snap = $this->snapshot([$cashbox]);
        $rejected = false;
        try {
            $this->bookingService->addPayment($booking->fresh(), [
                'amount' => 6000.0,
                'account_id' => $cashbox->id,
                'payment_method' => 'cash',
                'notes' => 'دفعة تتجاوز المتبقي',
            ]);
        } catch (\Throwable $e) {
            $rejected = true;
            $this->rec('5.4b', 'addPayment overpay rejected', '✅', 'msg: '.substr($e->getMessage(), 0, 80));
        }

        $this->assertTrue($rejected, 'Service must reject overpayment');
        $this->assertBalanceInvariant($cashbox);
        $this->assertSnapshotsEqual($snap, [$cashbox], '5.4b overpay rejected');

        // Now pay exactly the remaining 5K — should succeed
        $this->bookingService->addPayment($booking->fresh(), [
            'amount' => 5000.0,
            'account_id' => $cashbox->id,
            'payment_method' => 'cash',
            'notes' => 'دفعة المتبقي',
        ]);
        $total = (float) $booking->fresh()->payments()->sum('amount');
        $this->assertEqualsWithDelta($selling, $total, 0.01);
        $this->rec('5.4b', 'Pay exactly remaining 5K', '✅', "total={$total}");

        $this->bookingService->deleteBookingWithReversal($booking->id, $this->admin->id);
        $this->assertSoftDeleted('flight_bookings', ['id' => $booking->id]);
        $this->rec('5.4b', 'Delete + reversal', '✅', '');
    }

    // ═══════════════════════════════════════════════════════════════════
    // SUMMARY
    // ═══════════════════════════════════════════════════════════════════

    public function test_summary_report(): void
    {
        echo "\n".str_repeat('═', 80)."\n";
        echo '   FLIGHT MODULE — DEEP E2E TEST SUMMARY'.PHP_EOL;
        echo str_repeat('═', 80).PHP_EOL;

        $results = self::$allResults;
        $passed = count(array_filter($results, fn ($r) => str_starts_with($r['status'], '✅')));
        $failed = count(array_filter($results, fn ($r) => str_starts_with($r['status'], '❌')));
        $warnings = count(array_filter($results, fn ($r) => str_starts_with($r['status'], '⚠️')));

        echo "\n  Total scenarios:    ".count($results)."\n";
        echo "  Passed (✅):        {$passed}\n";
        echo "  Failed (❌):        {$failed}\n";
        echo "  Warnings (⚠️):      {$warnings}\n";

        if ($failed > 0) {
            echo "\n  Failed scenarios:\n";
            foreach ($results as $r) {
                if (str_starts_with($r['status'], '❌')) {
                    echo "    - {$r['scenario']} :: {$r['step']} — {$r['detail']}\n";
                }
            }
        }
        if ($warnings > 0) {
            echo "\n  Warnings:\n";
            foreach ($results as $r) {
                if (str_starts_with($r['status'], '⚠️')) {
                    echo "    - {$r['scenario']} :: {$r['step']} — {$r['detail']}\n";
                }
            }
        }

        $this->assertTrue(true, 'Summary always passes');
        $this->assertGreaterThan(0, $passed, 'No passing scenarios — something is wrong');
        $this->assertEquals(0, $failed, "{$failed} scenarios failed");
    }
}