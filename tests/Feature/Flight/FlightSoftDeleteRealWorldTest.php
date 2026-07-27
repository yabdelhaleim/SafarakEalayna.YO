<?php

namespace Tests\Feature\Flight;

use App\Enums\FlightBookingStatus;
use App\Models\Account;
use App\Models\AccountEntry;
use App\Models\Customer;
use App\Models\Flight\FlightBooking;
use App\Models\Flight\FlightCarrier;
use App\Models\Flight\FlightPayment;
use App\Models\Flight\FlightSystem;
use App\Models\User;
use App\Services\Finance\LedgerClearingAccounts;
use App\Services\Flight\FlightBookingService;
use App\Services\Flight\FlightCarrierRechargeService;
use App\Support\Finance\LedgerBalanceMutationGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Real-world soft-delete E2E test for the Flight Module.
 *
 * Exercises every realistic path a user could take in production:
 *   1. Book → pay full → soft-delete
 *   2. Book → pay partial → refund → soft-delete (cancelled state)
 *   3. Book → cancel (no refund) → soft-delete
 *   4. Book → pay multiple installments → modification → soft-delete
 *   5. Book in KWD → pay KWD → soft-delete (the production -300 bug scenario)
 *
 * After every flow, asserts the project's CRITICAL accounting invariants:
 *   - Account.balance == SUM(credit) - SUM(debit) on AccountEntry rows
 *   - Every same-currency transaction is balanced (SUM(debit) == SUM(credit))
 *   - Net balance delta == 0 for every financial account touched
 *   - No cashbox ever goes negative
 */
class FlightSoftDeleteRealWorldTest extends TestCase
{
    use RefreshDatabase;

    protected FlightBookingService $bookingService;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bookingService = app(FlightBookingService::class);

        $this->admin = User::factory()->create([
            'name' => 'Real World Admin',
            'email' => 'realworld@test.com',
            'role' => 'admin',
            'is_active' => true,
        ]);
        $this->actingAs($this->admin);
    }

    /** SCENARIO 1: Book → pay full → soft-delete (the simplest path) */
    public function test_scenario1_book_pay_full_soft_delete(): void
    {
        $fx = $this->buildFixture('EGP', 'عميل سيناريو 1');
        $customer = $fx['customer'];
        $system = $fx['system'];
        $carrier = $fx['carrier'];
        $cashbox = $fx['cashbox'];

        // Capture all financial positions BEFORE the booking
        $snapshot = $this->snapshotBalances([$cashbox->id, $carrier->id, $system->id]);

        // ACT: User creates a booking and pays the full amount
        $booking = $this->bookingService->createBooking([
            'customer_id' => $customer->id,
            'airline_name' => 'EgyptAir',
            'from_airport' => 'CAI',
            'to_airport' => 'JED',
            'departure_date' => now()->addDays(7)->toDateString(),
            'trip_type' => 'one_way',
            'currency' => 'EGP',
            'purchase_price' => 15000,
            'selling_price' => 18000,
            'flight_system_id' => $system->id,
            'flight_carrier_id' => $carrier->id,
            'purchase_balance_source' => 'carrier',
            'pnr' => 'SC1X'.uniqid(),
            'passengers' => [
                ['first_name' => 'علي', 'last_name' => 'محمد', 'passenger_type' => 'adult'],
            ],
            'payment' => [
                'amount' => 18000,
                'account_id' => $cashbox->id,
                'payment_method' => 'cash',
                'notes' => 'دفع كامل',
            ],
        ]);

        // Verify booking state
        $this->assertEquals(18000, (float) $booking->fresh()->selling_price);
        $this->assertEquals(18000, (float) $booking->fresh()->payments()->sum('amount'));

        // INVARIANT after booking
        $this->assertEveryAccountInvariant();
        $this->assertCashboxNotNegative($cashbox);
        $this->assertEveryTransactionBalanced();

        // ACT: User deletes the booking (e.g., duplicate entry)
        $this->bookingService->deleteBookingWithReversal($booking->id, $this->admin->id);

        // ASSERT: balances must match the snapshot taken before the booking
        $this->assertBalancesUnchanged($snapshot, [$cashbox->id, $carrier->id, $system->id]);
        $this->assertEveryAccountInvariant();
        $this->assertEveryTransactionBalanced();
        $this->assertBookingSoftDeleted($booking);
    }

    /** SCENARIO 2: Book → pay partial → cancel (with refund) → soft-delete */
    public function test_scenario2_book_partial_pay_cancel_soft_delete(): void
    {
        $fx = $this->buildFixture('EGP', 'عميل سيناريو 2');
        $customer = $fx['customer'];
        $system = $fx['system'];
        $carrier = $fx['carrier'];
        $cashbox = $fx['cashbox'];

        $snapshot = $this->snapshotBalances([$cashbox->id, $carrier->id, $system->id]);

        $booking = $this->bookingService->createBooking([
            'customer_id' => $customer->id,
            'airline_name' => 'Emirates',
            'from_airport' => 'CAI',
            'to_airport' => 'DXB',
            'departure_date' => now()->addDays(10)->toDateString(),
            'trip_type' => 'one_way',
            'currency' => 'EGP',
            'purchase_price' => 20000,
            'selling_price' => 25000,
            'flight_system_id' => $system->id,
            'flight_carrier_id' => $carrier->id,
            'purchase_balance_source' => 'carrier',
            'pnr' => 'SC2X'.uniqid(),
            'passengers' => [
                ['first_name' => 'فاطمة', 'last_name' => 'حسن', 'passenger_type' => 'adult'],
            ],
            'payment' => [
                'amount' => 10000,  // half payment
                'account_id' => $cashbox->id,
                'payment_method' => 'cash',
                'notes' => 'دفع نص',
            ],
        ]);

        $this->bookingService->addPayment($booking, [
            'amount' => 5000,  // second installment
            'account_id' => $cashbox->id,
            'payment_method' => 'cash',
            'notes' => 'دفعة ثانية',
        ]);

        $this->assertEquals(15000, (float) $booking->fresh()->payments()->sum('amount'));

        // User cancels the booking with 1000 EGP penalty
        $booking->update(['status' => FlightBookingStatus::CONFIRMED]);
        $refund = $this->bookingService->cancelBooking($booking, [
            'airline_penalty' => 600,
            'office_penalty' => 400,
            'account_id' => $cashbox->id,
            'notes' => 'إلغاء العميل',
        ]);
        $this->assertEquals(14000.0, (float) $refund->refund_amount);

        $this->assertEveryAccountInvariant();
        $this->assertEveryTransactionBalanced();

        // ACT: User deletes the cancelled booking
        $this->bookingService->deleteBookingWithReversal($booking->id, $this->admin->id);

        $this->assertBalancesUnchanged($snapshot, [$cashbox->id, $carrier->id, $system->id]);
        $this->assertEveryAccountInvariant();
        $this->assertEveryTransactionBalanced();
        $this->assertBookingSoftDeleted($booking);
    }

    /** SCENARIO 3: Book → cancel without refund → soft-delete */
    public function test_scenario3_book_cancel_no_refund_soft_delete(): void
    {
        $fx = $this->buildFixture('EGP', 'عميل سيناريو 3');
        $customer = $fx['customer'];
        $system = $fx['system'];
        $carrier = $fx['carrier'];
        $cashbox = $fx['cashbox'];

        $snapshot = $this->snapshotBalances([$cashbox->id, $carrier->id, $system->id]);

        // Book with FULL payment first
        $booking = $this->bookingService->createBooking([
            'customer_id' => $customer->id,
            'airline_name' => 'Qatar Airways',
            'from_airport' => 'CAI',
            'to_airport' => 'DOH',
            'departure_date' => now()->addDays(15)->toDateString(),
            'trip_type' => 'round_trip',
            'currency' => 'EGP',
            'purchase_price' => 10000,
            'selling_price' => 12000,
            'flight_system_id' => $system->id,
            'flight_carrier_id' => $carrier->id,
            'purchase_balance_source' => 'carrier',
            'pnr' => 'SC3X'.uniqid(),
            'passengers' => [
                ['first_name' => 'سامي', 'last_name' => 'خالد', 'passenger_type' => 'adult'],
            ],
            'payment' => [
                'amount' => 12000,
                'account_id' => $cashbox->id,
                'payment_method' => 'cash',
            ],
        ]);

        $booking->update(['status' => FlightBookingStatus::CONFIRMED]);

        // Cancel with FULL penalty (refund = 0)
        $this->bookingService->cancelBooking($booking, [
            'airline_penalty' => 7000,
            'office_penalty' => 5000,
            'account_id' => $cashbox->id,
        ]);

        $this->assertEveryAccountInvariant();

        // ACT: delete
        $this->bookingService->deleteBookingWithReversal($booking->id, $this->admin->id);

        $this->assertBalancesUnchanged($snapshot, [$cashbox->id, $carrier->id, $system->id]);
        $this->assertEveryAccountInvariant();
        $this->assertEveryTransactionBalanced();
    }

    /** SCENARIO 4: Book → 3 installments → soft-delete (multiple payments) */
    public function test_scenario4_book_three_installments_soft_delete(): void
    {
        $fx = $this->buildFixture('EGP', 'عميل سيناريو 4');
        $customer = $fx['customer'];
        $system = $fx['system'];
        $carrier = $fx['carrier'];
        $cashbox = $fx['cashbox'];

        $snapshot = $this->snapshotBalances([$cashbox->id, $carrier->id, $system->id]);

        $booking = $this->bookingService->createBooking([
            'customer_id' => $customer->id,
            'airline_name' => 'Saudi Airlines',
            'from_airport' => 'CAI',
            'to_airport' => 'RUH',
            'departure_date' => now()->addDays(20)->toDateString(),
            'trip_type' => 'one_way',
            'currency' => 'EGP',
            'purchase_price' => 30000,
            'selling_price' => 36000,
            'flight_system_id' => $system->id,
            'flight_carrier_id' => $carrier->id,
            'purchase_balance_source' => 'carrier',
            'pnr' => 'SC4X'.uniqid(),
            'passengers' => [
                ['first_name' => 'أحمد', 'last_name' => 'يوسف', 'passenger_type' => 'adult'],
            ],
        ]);

        // Three installments
        $this->bookingService->addPayment($booking, [
            'amount' => 12000, 'account_id' => $cashbox->id,
            'payment_method' => 'cash', 'notes' => 'القسط الأول',
        ]);
        $this->bookingService->addPayment($booking, [
            'amount' => 12000, 'account_id' => $cashbox->id,
            'payment_method' => 'bank_transfer', 'notes' => 'القسط الثاني',
        ]);
        $this->bookingService->addPayment($booking, [
            'amount' => 12000, 'account_id' => $cashbox->id,
            'payment_method' => 'cash', 'notes' => 'القسط الثالث',
        ]);

        $this->assertEquals(36000, (float) $booking->fresh()->payments()->sum('amount'));
        $this->assertEveryAccountInvariant();

        // ACT: delete the booking with 3 payments — all 3 must reverse
        $this->bookingService->deleteBookingWithReversal($booking->id, $this->admin->id);

        $this->assertBalancesUnchanged($snapshot, [$cashbox->id, $carrier->id, $system->id]);
        $this->assertEveryAccountInvariant();
        $this->assertEveryTransactionBalanced();
    }

    /** SCENARIO 5: KWD booking paid in KWD cashbox → soft-delete (the -300 KWD bug) */
    public function test_scenario5_kwd_same_ccy_soft_delete(): void
    {
        $fx = $this->buildFixture('KWD', 'عميل دينار سيناريو 5');
        $customer = $fx['customer'];
        $system = $fx['system'];
        $carrier = $fx['carrier'];
        $cashbox = $fx['cashbox'];  // KWD cashbox

        $snapshot = $this->snapshotBalances([$cashbox->id, $carrier->id, $system->id]);
        $exchangeRate = 160.0;

        // 100 KWD = 16000 EGP booking, paid in KWD cashbox
        $booking = $this->bookingService->createBooking([
            'customer_id' => $customer->id,
            'airline_name' => 'Jazeera Airways',
            'from_airport' => 'CAI',
            'to_airport' => 'KWI',
            'departure_date' => now()->addDays(12)->toDateString(),
            'trip_type' => 'one_way',
            'currency' => 'KWD',
            'foreign_currency' => 'KWD',
            'exchange_rate' => $exchangeRate,
            'purchase_price_foreign' => 50.0,  // 8000 EGP
            'purchase_price' => 8000.0,
            'selling_price' => 100.0 * $exchangeRate,  // 16000 EGP selling
            'flight_system_id' => $system->id,
            'flight_carrier_id' => $carrier->id,
            'purchase_balance_source' => 'carrier',
            'pnr' => 'SC5X'.uniqid(),
            'passengers' => [
                ['first_name' => 'خالد', 'last_name' => 'الجابر', 'passenger_type' => 'adult'],
            ],
            // Payment in KWD (matches cashbox currency)
            'payment' => [
                'amount' => 100.0,  // 100 KWD raw, NOT 16000 EGP
                'account_id' => $cashbox->id,
                'payment_method' => 'cash',
            ],
        ]);

        // The cashbox must have gone UP by exactly 100 KWD (not 0.625 KWD from the bug)
        $cashboxAfterBooking = (float) $cashbox->fresh()->balance;
        $expectedCashbox = $snapshot[$cashbox->id] + 100.0;
        $this->assertEqualsWithDelta(
            $expectedCashbox,
            $cashboxAfterBooking,
            0.01,
            "KWD cashbox must reflect exact 100 KWD payment (production -300 bug scenario)"
        );
        $this->assertCashboxNotNegative($cashbox);

        $this->assertEveryAccountInvariant();

        // ACT: delete the KWD booking
        $this->bookingService->deleteBookingWithReversal($booking->id, $this->admin->id);

        // After deletion: cashbox must be back to snapshot
        $this->assertBalancesUnchanged($snapshot, [$cashbox->id, $carrier->id, $system->id]);
        $this->assertEveryAccountInvariant();
        $this->assertEveryTransactionBalanced();
    }

    /** SCENARIO 6: KWD booking paid in EGP cashbox → soft-delete (cross-ccy scenario) */
    public function test_scenario6_kwd_paid_in_egp_soft_delete(): void
    {
        // Two-currency fixture: KWD system/carrier + EGP cashbox
        $customer = $this->createCustomer('عميل سيناريو 6');
        $system = FlightSystem::create([
            'name' => 'KWD System', 'code' => 'KSC6'.uniqid(),
            'type' => 'gds', 'is_active' => true, 'currency' => 'KWD',
            'credit_limit' => 0, 'created_by' => $this->admin->id,
        ]);
        $carrier = FlightCarrier::create([
            'name' => 'Jazeera KWD Carrier', 'code' => 'JSC6'.uniqid(),
            'flight_system_id' => $system->id, 'currency' => 'KWD',
            'credit_limit' => 10000, 'is_active' => true, 'created_by' => $this->admin->id,
        ]);

        // KWD cashbox for carrier recharge
        $kwdCashbox = $this->createAccount('KWD Cashbox', 'cashbox', 'KWD', 5000);
        $egpCashbox = $this->createAccount('EGP Cashbox', 'cashbox', 'EGP', 5000000);

        // Recharge KWD carrier from KWD cashbox
        app(FlightCarrierRechargeService::class)->rechargeFromAccount(
            $carrier, $kwdCashbox, 5000.0, 'KWD setup'
        );

        $snapshot = $this->snapshotBalances([$egpCashbox->id, $kwdCashbox->id, $carrier->id, $system->id]);
        $exchangeRate = 160.0;

        $booking = $this->bookingService->createBooking([
            'customer_id' => $customer->id,
            'airline_name' => 'Jazeera',
            'from_airport' => 'CAI',
            'to_airport' => 'KWI',
            'departure_date' => now()->addDays(8)->toDateString(),
            'trip_type' => 'one_way',
            'currency' => 'KWD',
            'foreign_currency' => 'KWD',
            'exchange_rate' => $exchangeRate,
            'purchase_price_foreign' => 50.0,
            'selling_price' => 100.0 * $exchangeRate,  // 16000 EGP
            'flight_system_id' => $system->id,
            'flight_carrier_id' => $carrier->id,
            'purchase_balance_source' => 'carrier',
            'pnr' => 'SC6X'.uniqid(),
            'passengers' => [
                ['first_name' => 'محمد', 'last_name' => 'الفهد', 'passenger_type' => 'adult'],
            ],
            'payment' => [
                'amount' => 100.0 * $exchangeRate,  // 16000 EGP
                'account_id' => $egpCashbox->id,   // EGP cashbox
                'payment_method' => 'cash',
            ],
        ]);

        $this->assertEveryAccountInvariant();

        // ACT: delete
        $this->bookingService->deleteBookingWithReversal($booking->id, $this->admin->id);

        $this->assertBalancesUnchanged($snapshot, [$egpCashbox->id, $kwdCashbox->id, $carrier->id, $system->id]);
        $this->assertEveryAccountInvariant();
        $this->assertEveryTransactionBalanced();
    }

    /** SCENARIO 7: Multiple bookings soft-deleted in sequence (stress test) */
    public function test_scenario7_sequential_soft_deletes(): void
    {
        $fx = $this->buildFixture('EGP', 'عميل ضغط');
        $customer = $fx['customer'];
        $system = $fx['system'];
        $carrier = $fx['carrier'];
        $cashbox = $fx['cashbox'];

        $snapshot = $this->snapshotBalances([$cashbox->id, $carrier->id, $system->id]);

        $bookings = [];
        for ($i = 1; $i <= 5; $i++) {
            $bookings[] = $this->bookingService->createBooking([
                'customer_id' => $customer->id,
                'airline_name' => "Airline #$i",
                'from_airport' => 'CAI',
                'to_airport' => 'JED',
                'departure_date' => now()->addDays(5 + $i)->toDateString(),
                'trip_type' => 'one_way',
                'currency' => 'EGP',
                'purchase_price' => 5000,
                'selling_price' => 6000,
                'flight_system_id' => $system->id,
                'flight_carrier_id' => $carrier->id,
                'purchase_balance_source' => 'carrier',
                'pnr' => "STR$i".uniqid(),
                'passengers' => [
                    ['first_name' => "Pax$i", 'last_name' => 'Test', 'passenger_type' => 'adult'],
                ],
                'payment' => [
                    'amount' => 6000,
                    'account_id' => $cashbox->id,
                    'payment_method' => 'cash',
                ],
            ]);
        }

        $this->assertEveryAccountInvariant();

        // Delete them all in reverse order
        foreach (array_reverse($bookings) as $booking) {
            $this->bookingService->deleteBookingWithReversal($booking->id, $this->admin->id);
        }

        $this->assertBalancesUnchanged($snapshot, [$cashbox->id, $carrier->id, $system->id]);
        $this->assertEveryAccountInvariant();
        $this->assertEveryTransactionBalanced();
    }

    // ── HELPERS ───────────────────────────────────────────────────────

    protected function buildFixture(string $currency, string $customerName): array
    {
        $customer = $this->createCustomer($customerName);

        $system = FlightSystem::create([
            'name' => "{$currency} System",
            'code' => substr($currency, 0, 2).'S'.uniqid(),
            'type' => 'gds', 'is_active' => true,
            'currency' => $currency,
            'credit_limit' => 5000, 'created_by' => $this->admin->id,
        ]);

        $carrier = FlightCarrier::create([
            'name' => "{$currency} Carrier",
            'code' => substr($currency, 0, 2).'C'.uniqid(),
            'flight_system_id' => $system->id,
            'currency' => $currency,
            'credit_limit' => 100000, 'is_active' => true,
            'created_by' => $this->admin->id,
        ]);

        $cashbox = $this->createAccount(
            "{$currency} Cashbox", 'cashbox', $currency, 100000
        );

        // Recharge the carrier so the booking debit has headroom
        app(FlightCarrierRechargeService::class)->rechargeFromAccount(
            $carrier, $cashbox, 50000.0, "Fixture setup {$currency}"
        );

        $cashbox->refresh();
        $carrier->refresh();

        return [
            'customer' => $customer,
            'system' => $system,
            'carrier' => $carrier,
            'cashbox' => $cashbox,
        ];
    }

    protected function createCustomer(string $name): Customer
    {
        return Customer::create([
            'full_name' => $name,
            'phone' => '010'.substr(md5($name.microtime(true)), 0, 8),
            'email' => substr(md5($name), 0, 8).'@test.com',
            'national_id' => '29'.substr(md5($name.microtime(true)), 0, 12),
            'city' => 'Cairo',
        ]);
    }

    /**
     * Create an account with a properly-paired opening AccountEntry so the
     * project's `balance = SUM(credit) - SUM(debit)` invariant holds from t=0.
     */
    protected function createAccount(string $name, string $type, string $currency, float $openingBalance): Account
    {
        $account = Account::create([
            'name' => $name, 'type' => $type, 'currency' => $currency,
            'balance' => 0, 'is_active' => true,
            'owner_type' => 'office', 'module_type' => 'tourism',
            'created_by' => $this->admin->id,
        ]);

        if ($openingBalance > 0) {
            LedgerBalanceMutationGuard::run(function () use ($account, $openingBalance) {
                $account->balance = $openingBalance;
                $account->save();
            });
            AccountEntry::create([
                'account_id' => $account->id,
                'transaction_id' => null,
                'debit' => 0.00, 'credit' => $openingBalance,
                'balance_after' => $openingBalance,
                'notes' => "رصيد افتتاحي {$currency}",
            ]);
        }

        return $account->refresh();
    }

    /** Snapshot current balances for a set of accounts. */
    protected function snapshotBalances(array $accountIds): array
    {
        $snap = [];
        foreach ($accountIds as $id) {
            $snap[$id] = (float) Account::find($id)->fresh()->balance;
        }
        return $snap;
    }

    /** Assert that balances are back to the snapshot (no drift). */
    protected function assertBalancesUnchanged(array $snapshot, array $accountIds, string $message = ''): void
    {
        foreach ($accountIds as $id) {
            $current = (float) Account::find($id)->fresh()->balance;
            $this->assertEqualsWithDelta(
                $snapshot[$id],
                $current,
                0.01,
                "Account #{$id} balance changed from {$snapshot[$id]} to {$current}. {$message}"
            );
        }
    }

    /** Assert balance == SUM(credit) - SUM(debit) for ALL accounts. */
    protected function assertEveryAccountInvariant(): void
    {
        $accounts = Account::all();
        foreach ($accounts as $account) {
            $entries = AccountEntry::where('account_id', $account->id)->get();
            $expected = round((float) $entries->sum('credit') - (float) $entries->sum('debit'), 2);
            $actual = round((float) $account->balance, 2);
            $this->assertEqualsWithDelta(
                $expected, $actual, 0.01,
                "INVARIANT VIOLATED for account #{$account->id} ({$account->name}, {$account->currency}): ".
                "balance {$actual} ≠ SUM(credit)-SUM(debit) = {$expected}"
            );
        }
    }

    /** Assert every same-currency transaction is balanced (SUM debit == SUM credit). */
    protected function assertEveryTransactionBalanced(): void
    {
        $txs = DB::table('transactions')->pluck('id');
        foreach ($txs as $txId) {
            $entries = DB::table('account_entries')->where('transaction_id', $txId)->get();
            if ($entries->isEmpty()) {
                continue;
            }
            $accountIds = $entries->pluck('account_id')->unique()->values();
            $currencies = DB::table('accounts')->whereIn('id', $accountIds)->pluck('currency')->unique();
            if ($currencies->count() === 1) {
                $sumDebit = (float) $entries->sum('debit');
                $sumCredit = (float) $entries->sum('credit');
                $this->assertEqualsWithDelta(
                    $sumDebit, $sumCredit, 0.001,
                    "Unbalanced transaction #{$txId}: debit={$sumDebit} credit={$sumCredit}"
                );
            }
        }
    }

    protected function assertCashboxNotNegative(Account $cashbox): void
    {
        $this->assertGreaterThanOrEqual(
            0,
            (float) $cashbox->fresh()->balance,
            "Cashbox {$cashbox->name} went NEGATIVE — this is the production -300 KWD bug!"
        );
    }

    protected function assertBookingSoftDeleted(FlightBooking $booking): void
    {
        $this->assertTrue(
            $booking->fresh()->trashed(),
            "Booking #{$booking->id} must be soft-deleted"
        );
    }
}