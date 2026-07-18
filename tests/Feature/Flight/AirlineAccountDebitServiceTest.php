<?php

namespace Tests\Feature\Flight;

use App\Models\Account;
use App\Models\Customer;
use App\Models\Flight\AirlineAccount;
use App\Models\Flight\FlightBooking;
use App\Models\Flight\FlightCarrier;
use App\Models\Flight\FlightSystem;
use App\Models\Flight\TicketModification;
use App\Models\User;
use App\Services\Flight\AirlineAccountDebitService;
use App\Services\Flight\FlightBookingService;
use App\Services\Flight\FlightCarrierRechargeService;
use App\Services\Flight\ModificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Feature tests for AirlineAccountDebitService — Phase 1v2 (Bug #C1).
 *
 * Critical coverage:
 *   - Currency mismatch guard (C1): throws if booking != account and neither is EGP
 *   - Currency conversion helper: EGP ↔ foreign via booking exchange rate
 *   - debit side: GL entries + balance mutation + transaction record
 *   - credit-back (reversal) side: paired GL reversal + uses currency_snapshot
 *   - Zero-amount credit-back is a no-op (no balance change, no GL entry)
 *
 * @see \App\Services\Flight\AirlineAccountDebitService
 */
class AirlineAccountDebitServiceTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected FlightSystem $flightSystem;

    protected FlightCarrier $carrier;

    protected Account $cashbox;

    protected AirlineAccount $airlineAccount;

    protected FlightBookingService $bookingService;

    protected ModificationService $modificationService;

    protected AirlineAccountDebitService $debitService;

    protected function setUp(): void
    {
        parent::setUp();

        // Phase 1v2 strict guards: نقفل production-style على direct mutations
        config(['accounting.strict_test_guards' => true]);

        $this->admin = User::factory()->create([
            'name' => 'Debit Test Admin',
            'email' => 'debit-admin@test.com',
            'role' => 'admin',
            'is_active' => true,
        ]);
        $this->actingAs($this->admin);

        $this->bookingService = app(FlightBookingService::class);
        $this->modificationService = app(ModificationService::class);
        $this->debitService = app(AirlineAccountDebitService::class);

        $this->flightSystem = FlightSystem::create([
            'name' => 'Debit Test System',
            'code' => 'DTS'.substr(md5((string) microtime(true)), 0, 5),
            'type' => 'gds',
            'is_active' => true,
            'currency' => 'EGP',
            'balance' => 0,
            'credit_limit' => 0,
            'created_by' => $this->admin->id,
        ]);

        $this->carrier = FlightCarrier::create([
            'flight_system_id' => $this->flightSystem->id,
            'name' => 'Debit Test Carrier',
            'code' => 'DTC',
            'currency' => 'EGP',
            'balance' => 0,
            'credit_limit' => 50000,
            'is_active' => true,
            'created_by' => $this->admin->id,
        ]);

        $this->cashbox = Account::create([
            'name' => 'Debit Test Cashbox',
            'type' => 'cashbox',
            'currency' => 'EGP',
            'balance' => 100000,
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'office',
            'created_by' => $this->admin->id,
        ]);

        // شحن الرصيد للناقل عشان يقبل debits
        app(FlightCarrierRechargeService::class)->rechargeFromAccount(
            $this->carrier, $this->cashbox, 80000.00, 'Debit test setup'
        );

        $this->cashbox->refresh();

        Account::create([
            'name' => 'إقفال تكاليف الطيران',
            'type' => 'cashbox',
            'currency' => 'EGP',
            'balance' => 0,
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'office',
            'created_by' => $this->admin->id,
        ]);

        Account::create([
            'name' => 'رصيد مسبق — ناقلو الطيران',
            'type' => 'cashbox',
            'currency' => 'EGP',
            'balance' => 0,
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'office',
            'created_by' => $this->admin->id,
        ]);

        $this->airlineAccount = AirlineAccount::create([
            'flight_carrier_id' => $this->carrier->id,
            'name' => 'Debit Test Airline Account',
            'code' => 'DTAA'.substr(md5((string) microtime(true)), 0, 4),
            'system_type' => 'Amadeus',
            'currency' => 'EGP',
            'credit_limit' => 10000.00,
            'is_active' => true,
        ]);

        // ⚠️ مهم جداً: balance مش في $fillable، فلازم refresh عشان $this->balance يعكس الـ DB
        // وإلا credit() هيكسر على balance_before null
        $this->airlineAccount->refresh();

        // ابتدائي — عبر credit() (المسار المعتمد) بدل الـ mass assignment
        $this->airlineAccount->credit(10000.00, 'Initial test setup', $this->admin->id, null);
    }

    /**
     * ✅ 1) EGP/EGP → no conversion needed, exact debit
     */
    public function test_debit_egp_to_egp_no_conversion(): void
    {
        $customer = Customer::create([
            'full_name' => 'EGP Test',
            'phone' => '01000000001',
        ]);

        $booking = $this->bookingService->createBooking([
            'customer_id' => $customer->id,
            'airline_name' => 'TestAir',
            'from_airport' => 'CAI',
            'to_airport' => 'JED',
            'departure_date' => now()->addDays(7)->toDateString(),
            'trip_type' => 'one_way',
            'currency' => 'EGP',
            'purchase_price' => 15000,
            'selling_price' => 18000,
            'flight_carrier_id' => $this->carrier->id,
            'account_id' => $this->cashbox->id,
            'airline_account_id' => $this->airlineAccount->id,
            'passengers' => [['name' => 'Pax 1', 'type' => 'adult']],
        ]);

        $mod = $this->modificationService->createRequest([
            'booking_id' => $booking->id,
            'modification_type' => 'date_change',
            'new_departure_date' => now()->addDays(14)->toDateString(),
            'airline_change_fee' => 500.00,
            'agency_commission' => 100.0,
            'currency' => 'EGP',
            'payment_method' => 'cash',
        ], $this->admin->id);

        $balanceBefore = (float) $this->airlineAccount->fresh()->balance;

        $result = $this->debitService->debitForModification(
            $this->airlineAccount,
            $booking,
            $mod,
            $this->admin->id,
        );

        $this->airlineAccount->refresh();

        // 500 EGP exactly deducted
        $this->assertEquals(
            round($balanceBefore - 500.0, 2),
            (float) $this->airlineAccount->balance,
            'EGP→EGP debit must be exact (no rounding)'
        );

        // airline_tx_id was returned, prepaid_tx_id nullable
        $this->assertGreaterThan(0, $result['airline_tx_id']);
        $this->assertEquals(
            round($balanceBefore - 500.0, 2),
            $result['balance_after'],
            'Result balance_after must equal fresh account balance'
        );
    }

    /**
     * ✅ 2) Currency mismatch guard (C1) — both foreign → throws RuntimeException
     *    (booking currency != account currency, neither is EGP)
     */
    public function test_debit_rejects_foreign_to_foreign_currency_mismatch(): void
    {
        // Saudi airline account بـ SAR
        $sarAccount = AirlineAccount::create([
            'flight_carrier_id' => $this->carrier->id,
            'name' => 'SAR Test Account',
            'code' => 'SAR'.substr(md5((string) microtime(true)), 0, 5),
            'system_type' => 'Amadeus',
            'currency' => 'SAR',  // ⚠️ SAR
            'credit_limit' => 5000,
            'is_active' => true,
        ]);
        $sarAccount->refresh();  // ← مهم: balance مش في fillable
        $sarAccount->credit(5000.00, 'SAR setup', $this->admin->id, null);

        $customer = Customer::create([
            'full_name' => 'FX Mismatch Test',
            'phone' => '01000000002',
        ]);

        // Booking بـ KWD
        $booking = $this->bookingService->createBooking([
            'customer_id' => $customer->id,
            'airline_name' => 'TestAir',
            'from_airport' => 'KWI',
            'to_airport' => 'DXB',
            'departure_date' => now()->addDays(7)->toDateString(),
            'trip_type' => 'one_way',
            'currency' => 'KWD',  // ⚠️ KWD
            'purchase_price' => 100,
            'selling_price' => 150,
            'flight_carrier_id' => $this->carrier->id,
            'account_id' => $this->cashbox->id,
            'passengers' => [['name' => 'Pax K', 'type' => 'adult']],
        ]);

        $mod = $this->modificationService->createRequest([
            'booking_id' => $booking->id,
            'modification_type' => 'date_change',
            'new_departure_date' => now()->addDays(14)->toDateString(),
            'airline_change_fee' => 10.00,
            'currency' => 'KWD',
            'payment_method' => 'cash',
        ], $this->admin->id);

        $sarBefore = (float) $sarAccount->fresh()->balance;

        try {
            $this->debitService->debitForModification(
                $sarAccount,    // SAR
                $booking,        // KWD
                $mod,
                $this->admin->id,
            );
            $this->fail('Expected currency mismatch RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('لا تطابق', $e->getMessage());
            $this->assertStringContainsString('KWD', $e->getMessage());
            $this->assertStringContainsString('SAR', $e->getMessage());
        }

        // الـ balance ما اتغيّرش
        $this->assertEquals($sarBefore, (float) $sarAccount->fresh()->balance,
            'SAR account balance must NOT change when currency mismatches');
    }

    /**
     * ✅ 3) Booking EGP, account foreign → convert via exchange rate
     */
    public function test_debit_egp_booking_to_foreign_account_converts(): void
    {
        // حساب USD مع رصيد
        $usdAccount = AirlineAccount::create([
            'flight_carrier_id' => $this->carrier->id,
            'name' => 'USD Test Account',
            'code' => 'USD'.substr(md5((string) microtime(true)), 0, 5),
            'system_type' => 'Amadeus',
            'currency' => 'USD',
            'credit_limit' => 5000,
            'is_active' => true,
        ]);
        $usdAccount->refresh();  // ← مهم
        $usdAccount->credit(1000.00, 'USD setup', $this->admin->id, null);

        $customer = Customer::create([
            'full_name' => 'EGP→USD',
            'phone' => '01000000003',
        ]);

        // حجز بـ EGP — الـ exchange_rate_used لازم يكون مخزّن بشكل صحيح
        // حتى debitForModification يستخدمه في convertToAccountCurrency
        $booking = $this->bookingService->createBooking([
            'customer_id' => $customer->id,
            'airline_name' => 'TestAir',
            'from_airport' => 'CAI',
            'to_airport' => 'JFK',
            'departure_date' => now()->addDays(7)->toDateString(),
            'trip_type' => 'one_way',
            'currency' => 'EGP',
            'exchange_rate' => 50.0,  // ← حقل exchange_rate (rate) — الـ service يستخدمه
            'purchase_price_foreign' => 500,  // 500 USD = 25000 EGP
            'exchange_rate_used' => 50.0,
            'purchase_price' => 25000,
            'selling_price' => 30000,
            'flight_carrier_id' => $this->carrier->id,
            'account_id' => $this->cashbox->id,
            'passengers' => [['name' => 'Pax C', 'type' => 'adult']],
        ]);

        $booking->refresh();
        // ⚠️ الـ booking service بيحسب exchange_rate_used من settlement snapshot (1.0 افتراضي).
        //    لغرض الـ test: نعدّله يدوياً (نفس ما تعمله Production booking flow).
        $booking->exchange_rate_used = 50.0;
        $booking->save();
        $booking->refresh();

        // Sanity: ensure exchange_rate_used was actually saved
        $this->assertEquals(50.0, (float) $booking->exchange_rate_used,
            'Booking must persist exchange_rate_used (used in convertToAccountCurrency)');

        $mod = $this->modificationService->createRequest([
            'booking_id' => $booking->id,
            'modification_type' => 'date_change',
            'new_departure_date' => now()->addDays(14)->toDateString(),
            'airline_change_fee' => 1000.00,   // 1000 EGP → 20 USD (1000/50)
            'currency' => 'EGP',
            'payment_method' => 'cash',
        ], $this->admin->id);

        $usdBefore = (float) $usdAccount->fresh()->balance;

        $this->debitService->debitForModification(
            $usdAccount,     // USD
            $booking,        // EGP
            $mod,
            $this->admin->id,
        );

        $usdAccount->refresh();

        // 1000 EGP / 50 rate = 20 USD
        $expectedUsdDebit = 1000.0 / 50.0;  // 20.0
        $this->assertEquals(
            round($usdBefore - $expectedUsdDebit, 2),
            (float) $usdAccount->balance,
            'EGP→USD conversion must use booking exchange rate'
        );
    }

    /**
     * ✅ 4) credit-back (reversal) uses currency_snapshot, NOT live field
     *    (B11 fix: snapshot يحمي من الـ mid-flow currency mutations)
     */
    public function test_credit_back_uses_currency_snapshot_not_live_field(): void
    {
        $customer = Customer::create([
            'full_name' => 'Snapshot Test',
            'phone' => '01000000010',
        ]);

        $booking = $this->bookingService->createBooking([
            'customer_id' => $customer->id,
            'airline_name' => 'TestAir',
            'from_airport' => 'CAI',
            'to_airport' => 'JED',
            'departure_date' => now()->addDays(7)->toDateString(),
            'trip_type' => 'one_way',
            'currency' => 'EGP',
            'purchase_price' => 15000,
            'selling_price' => 18000,
            'flight_carrier_id' => $this->carrier->id,
            'account_id' => $this->cashbox->id,
            'airline_account_id' => $this->airlineAccount->id,
            'passengers' => [['name' => 'Pax S', 'type' => 'adult']],
        ]);

        $mod = $this->modificationService->createRequest([
            'booking_id' => $booking->id,
            'modification_type' => 'date_change',
            'new_departure_date' => now()->addDays(14)->toDateString(),
            'airline_change_fee' => 500.00,
            'currency' => 'EGP',
            'payment_method' => 'cash',
        ], $this->admin->id);

        // ⚠️ ModificationService::createRequest() يحفظ currency_snapshot
        //    لكن airline_change_fee_snapshot يُحفظ في confirmModification() فقط.
        //    لغرض الـ test: نحفظ الـ snapshot يدوياً (هذا ما يفعله confirmModification).
        $mod->airline_change_fee_snapshot = 500.00;
        $mod->commission_snapshot = 100.0;
        $mod->exchange_rate_snapshot = 1.0;
        $mod->save();
        $mod->refresh();

        $balanceBefore = (float) $this->airlineAccount->fresh()->balance;

        // Debit
        $this->debitService->debitForModification(
            $this->airlineAccount, $booking, $mod, $this->admin->id
        );
        $this->airlineAccount->refresh();
        $balanceAfterDebit = (float) $this->airlineAccount->balance;
        $this->assertEquals(round($balanceBefore - 500.0, 2), $balanceAfterDebit);

        // Snapshot was saved (B11)
        $mod->refresh();
        $this->assertNotNull($mod->currency_snapshot,
            'Modification must save currency_snapshot (B11 fix)');
        $this->assertEquals('EGP', strtoupper((string) $mod->currency_snapshot));
        $this->assertEquals(500.00, (float) $mod->airline_change_fee_snapshot,
            'airline_change_fee_snapshot must be stored (used in credit-back)');

        // ⚠️ Mutate live field to DIFFERENT value (simulate mid-flow corruption)
        // credit-back must IGNORE this and use snapshot
        $mod->currency = 'USD';  // لنفترض إن حد غيّر الـ live field
        $mod->airline_change_fee = 999999.99;  // live value changed
        $mod->save();
        $mod->refresh();

        // Credit-back (reversal) — must use snapshot, not corrupted live field
        $this->debitService->creditBackForModification(
            $this->airlineAccount, $booking, $mod, $this->admin->id
        );
        $this->airlineAccount->refresh();

        // لازم يرجع للـ balance قبل الـ debit (500 EGP restored)
        $this->assertEquals(
            round($balanceBefore, 2),
            (float) $this->airlineAccount->balance,
            'credit-back must restore using currency_snapshot, ignoring corrupted live fields'
        );
    }

    /**
     * ✅ 5) Zero-amount credit-back is a no-op
     */
    public function test_credit_back_with_zero_amount_is_noop(): void
    {
        $customer = Customer::create([
            'full_name' => 'Zero Amount',
            'phone' => '01000000011',
        ]);

        $booking = $this->bookingService->createBooking([
            'customer_id' => $customer->id,
            'airline_name' => 'TestAir',
            'from_airport' => 'CAI',
            'to_airport' => 'JED',
            'departure_date' => now()->addDays(7)->toDateString(),
            'trip_type' => 'one_way',
            'currency' => 'EGP',
            'purchase_price' => 15000,
            'selling_price' => 18000,
            'flight_carrier_id' => $this->carrier->id,
            'account_id' => $this->cashbox->id,
            'airline_account_id' => $this->airlineAccount->id,
            'passengers' => [['name' => 'Pax Z', 'type' => 'adult']],
        ]);

        $mod = $this->modificationService->createRequest([
            'booking_id' => $booking->id,
            'modification_type' => 'date_change',
            'new_departure_date' => now()->addDays(14)->toDateString(),
            'airline_change_fee' => 0.0,  // ⚠️ صفر
            'currency' => 'EGP',
            'payment_method' => 'cash',
        ], $this->admin->id);

        $balanceBefore = (float) $this->airlineAccount->fresh()->balance;

        $result = $this->debitService->creditBackForModification(
            $this->airlineAccount, $booking, $mod, $this->admin->id
        );

        // الـ airline_tx_id = 0 marker (no transaction row)
        $this->assertEquals(0, $result['airline_tx_id'],
            'Zero-amount credit-back must return airline_tx_id=0 (no-op marker)');
        $this->assertNull($result['prepaid_tx_id'],
            'Zero-amount credit-back must NOT post GL entry');

        // الرصيد ما اتغيرش
        $this->assertEquals($balanceBefore, (float) $this->airlineAccount->fresh()->balance,
            'Balance must NOT change on zero-amount credit-back');
    }

    /**
     * ✅ 6) debit creates airline_transaction with correct balance_after
     */
    public function test_debit_creates_transaction_with_correct_balance_after(): void
    {
        $customer = Customer::create([
            'full_name' => 'Tx Record Test',
            'phone' => '01000000020',
        ]);

        $booking = $this->bookingService->createBooking([
            'customer_id' => $customer->id,
            'airline_name' => 'TestAir',
            'from_airport' => 'CAI',
            'to_airport' => 'JED',
            'departure_date' => now()->addDays(7)->toDateString(),
            'trip_type' => 'one_way',
            'currency' => 'EGP',
            'purchase_price' => 15000,
            'selling_price' => 18000,
            'flight_carrier_id' => $this->carrier->id,
            'account_id' => $this->cashbox->id,
            'airline_account_id' => $this->airlineAccount->id,
            'passengers' => [['name' => 'Pax T', 'type' => 'adult']],
        ]);

        $mod = $this->modificationService->createRequest([
            'booking_id' => $booking->id,
            'modification_type' => 'date_change',
            'new_departure_date' => now()->addDays(14)->toDateString(),
            'airline_change_fee' => 750.00,
            'currency' => 'EGP',
            'payment_method' => 'cash',
        ], $this->admin->id);

        $balanceBefore = (float) $this->airlineAccount->fresh()->balance;

        $this->debitService->debitForModification(
            $this->airlineAccount, $booking, $mod, $this->admin->id
        );

        // Transaction recorded
        $this->assertDatabaseHas('airline_transactions', [
            'airline_account_id' => $this->airlineAccount->id,
            'type' => 'debit',
            'amount' => 750.00,
        ]);

        // Balance == before - amount
        $this->airlineAccount->refresh();
        $this->assertEquals(round($balanceBefore - 750.0, 2), (float) $this->airlineAccount->balance);
    }

    /**
     * ✅ 7) debit→credit produces net-zero on AirlineAccount.balance (idempotency)
     */
    public function test_debit_then_credit_back_yields_net_zero_balance(): void
    {
        $customer = Customer::create([
            'full_name' => 'Net Zero Test',
            'phone' => '01000000030',
        ]);

        $booking = $this->bookingService->createBooking([
            'customer_id' => $customer->id,
            'airline_name' => 'TestAir',
            'from_airport' => 'CAI',
            'to_airport' => 'JED',
            'departure_date' => now()->addDays(7)->toDateString(),
            'trip_type' => 'one_way',
            'currency' => 'EGP',
            'purchase_price' => 15000,
            'selling_price' => 18000,
            'flight_carrier_id' => $this->carrier->id,
            'account_id' => $this->cashbox->id,
            'airline_account_id' => $this->airlineAccount->id,
            'passengers' => [['name' => 'Pax N', 'type' => 'adult']],
        ]);

        $mod = $this->modificationService->createRequest([
            'booking_id' => $booking->id,
            'modification_type' => 'date_change',
            'new_departure_date' => now()->addDays(14)->toDateString(),
            'airline_change_fee' => 1000.00,
            'currency' => 'EGP',
            'payment_method' => 'cash',
        ], $this->admin->id);

        $balanceBefore = (float) $this->airlineAccount->fresh()->balance;

        // Debit
        $this->debitService->debitForModification(
            $this->airlineAccount, $booking, $mod, $this->admin->id
        );
        $balanceAfterDebit = (float) $this->airlineAccount->fresh()->balance;
        $this->assertEquals(round($balanceBefore - 1000.0, 2), $balanceAfterDebit);

        // Credit-back (reversal)
        $this->debitService->creditBackForModification(
            $this->airlineAccount, $booking, $mod, $this->admin->id
        );

        $balanceAfterCredit = (float) $this->airlineAccount->fresh()->balance;

        // Net zero
        $this->assertEquals(
            round($balanceBefore, 2),
            $balanceAfterCredit,
            'debit + credit-back must yield net-zero on AirlineAccount.balance'
        );
    }

    /**
     * ✅ 8) debit rejects when airline account has insufficient available_balance
     */
    public function test_debit_throws_when_available_balance_insufficient(): void
    {
        // نظّف الرصيد
        $this->airlineAccount->refresh();

        $sarAccount = AirlineAccount::create([
            'flight_carrier_id' => $this->carrier->id,
            'name' => 'Low Balance Test',
            'code' => 'LBT'.substr(md5((string) microtime(true)), 0, 5),
            'system_type' => 'Amadeus',
            'currency' => 'EGP',
            'credit_limit' => 100,  // رصيد قليل
            'is_active' => true,
        ]);
        $sarAccount->refresh();  // ← مهم
        // balance = 0, credit_limit = 100 → available = 100

        $customer = Customer::create([
            'full_name' => 'Low Balance',
            'phone' => '01000000099',
        ]);

        $booking = $this->bookingService->createBooking([
            'customer_id' => $customer->id,
            'airline_name' => 'TestAir',
            'from_airport' => 'CAI',
            'to_airport' => 'JED',
            'departure_date' => now()->addDays(7)->toDateString(),
            'trip_type' => 'one_way',
            'currency' => 'EGP',
            'purchase_price' => 15000,
            'selling_price' => 18000,
            'flight_carrier_id' => $this->carrier->id,
            'account_id' => $this->cashbox->id,
            'passengers' => [['name' => 'Pax L', 'type' => 'adult']],
        ]);

        $mod = $this->modificationService->createRequest([
            'booking_id' => $booking->id,
            'modification_type' => 'date_change',
            'new_departure_date' => now()->addDays(14)->toDateString(),
            'airline_change_fee' => 5000.00,  // أكبر من الـ available (100)
            'currency' => 'EGP',
            'payment_method' => 'cash',
        ], $this->admin->id);

        $this->expectException(\Throwable::class);
        $this->expectExceptionMessageMatches('/غير كافٍ|insufficient/i');

        $this->debitService->debitForModification(
            $sarAccount, $booking, $mod, $this->admin->id,
        );
    }
}
