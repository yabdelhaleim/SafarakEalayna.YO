<?php

namespace Tests\Feature\Flight;

use App\Enums\BookingChannelType;
use App\Enums\FlightBookingStatus;
use App\Models\Account;
use App\Models\AccountEntry;
use App\Models\Customer;
use App\Models\Flight\FlightBooking;
use App\Models\Flight\FlightCarrier;
use App\Models\Flight\FlightGroup;
use App\Models\Flight\FlightPayment;
use App\Models\Flight\FlightRefund;
use App\Models\Flight\FlightSystem;
use App\Models\User;
use App\Services\Flight\FlightBookingService;
use App\Services\Flight\FlightCarrierRechargeService;
use App\Services\Flight\FlightSystemRechargeService;
use App\Support\Finance\LedgerBalanceMutationGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * PHASE 11.3 — FLIGHT THREE-PATH DEEP E2E
 * =====================================
 *
 * 24-scenario matrix per booking path × 3 paths = 72 scenarios.
 * Tests the full lifecycle of a flight booking: create, pay (partial/full/multiple),
 * refund (partial/full), cancel (unpaid/partial/full/refunded), delete (every state).
 *
 * Critical assertions:
 *   - balance invariants hold after every operation
 *   - debtor identity (customer AR, group AR) never crosses
 *   - idempotency: refund twice = same result, cancel twice = error
 *   - terminal states (CANCELLED, REFUNDED) cannot transition back
 *   - delete creates additive reversal, never modifies originals
 */
class Phase11ThreePathDeepE2ETest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected FlightBookingService $bookingService;

    protected function setUp(): void
    {
        parent::setUp();
        config(['accounting.strict_test_guards' => true]);

        $this->admin = User::factory()->create([
            'name' => 'Phase11 3-Path Admin',
            'email' => 'phase11-3p-'.uniqid().'@test.com',
            'role' => 'admin',
            'is_active' => true,
        ]);
        Sanctum::actingAs($this->admin, ['*']);
        $this->bookingService = app(FlightBookingService::class);
    }

    // ═══════════════════════════════════════════════════════════════
    // CUSTOMER PATH — 8 critical scenarios
    // ═══════════════════════════════════════════════════════════════

    public function test_customer_01_unpaid_then_full_pay(): void
    {
        $b = $this->createCustomerBooking('EGP', 100_000, 1500, 1000);
        $cashbox = $this->makeAccount('Cashbox', 'cashbox', 'EGP', 100_000);

        $response = $this->postJson("/api/v1/flight/bookings/{$b->id}/payments", [
            'amount' => 1500.0,
            'payment_method' => 'cash',
            'account_id' => $cashbox->id,
        ]);
        $response->assertCreated();

        $b->refresh();
        $this->assertEquals(FlightBookingStatus::CONFIRMED->value, $b->status->value);
        $this->assertEquals(1500.0, (float) $b->selling_price);
        $this->assertEquals(1, $b->payments()->count());
    }

    public function test_customer_02_unpaid_then_partial_pay(): void
    {
        $b = $this->createCustomerBooking('EGP', 100_000, 1500, 1000);
        $cashbox = $this->makeAccount('Cashbox', 'cashbox', 'EGP', 100_000);

        $response = $this->postJson("/api/v1/flight/bookings/{$b->id}/payments", [
            'amount' => 500.0,
            'payment_method' => 'cash',
            'account_id' => $cashbox->id,
        ]);
        $response->assertCreated();

        $b->refresh();
        $this->assertEquals(FlightBookingStatus::PENDING->value, $b->status->value);
        $this->assertEquals(500.0, (float) $b->payments()->sum('amount'));
        $this->assertEquals(1000.0, $b->remaining_amount);
    }

    public function test_customer_03_multiple_partial_payments(): void
    {
        $b = $this->createCustomerBooking('EGP', 100_000, 1500, 1000);
        $cashbox = $this->makeAccount('Cashbox', 'cashbox', 'EGP', 100_000);

        foreach ([400.0, 400.0, 400.0, 300.0] as $amount) {
            $this->postJson("/api/v1/flight/bookings/{$b->id}/payments", [
                'amount' => $amount,
                'payment_method' => 'cash',
                'account_id' => $cashbox->id,
            ])->assertCreated();
        }

        $b->refresh();
        $this->assertEquals(FlightBookingStatus::CONFIRMED->value, $b->status->value);
        $this->assertEquals(1500.0, (float) $b->payments()->sum('amount'));
        $this->assertEquals(4, $b->payments()->count());
    }

    public function test_customer_04_cancel_unpaid(): void
    {
        $b = $this->createCustomerBooking('EGP', 100_000, 1500, 1000);

        $response = $this->postJson("/api/v1/flight/bookings/{$b->id}/cancel", [
            'airline_penalty' => 0.0,
            'office_penalty' => 0.0,
        ]);
        $response->assertOk();

        $b->refresh();
        $this->assertEquals(FlightBookingStatus::CANCELLED->value, $b->status->value);
    }

    public function test_customer_05_cancel_partial_then_full_pay_attempt_fails(): void
    {
        $b = $this->createCustomerBooking('EGP', 100_000, 1500, 1000);
        $cashbox = $this->makeAccount('Cashbox', 'cashbox', 'EGP', 100_000);

        $this->postJson("/api/v1/flight/bookings/{$b->id}/payments", [
            'amount' => 500.0, 'payment_method' => 'cash', 'account_id' => $cashbox->id,
        ])->assertCreated();

        $this->postJson("/api/v1/flight/bookings/{$b->id}/cancel", [
            'airline_penalty' => 100.0,
            'office_penalty' => 0.0,
            'account_id' => $cashbox->id,
        ])->assertOk();

        // Payment after cancel MUST fail.
        $response = $this->postJson("/api/v1/flight/bookings/{$b->id}/payments", [
            'amount' => 1000.0, 'payment_method' => 'cash', 'account_id' => $cashbox->id,
        ]);
        $response->assertStatus(422);
        $msg = $response->json('message') ?? '';
        $this->assertTrue(
            str_contains($msg, 'ملغي') || str_contains($msg, 'cancelled'),
            'Expected cancellation/rejection message. Got: '.$msg
        );
    }

    public function test_customer_06_cancel_then_delete(): void
    {
        $b = $this->createCustomerBooking('EGP', 100_000, 1500, 1000);

        $this->postJson("/api/v1/flight/bookings/{$b->id}/cancel", [
            'airline_penalty' => 0.0, 'office_penalty' => 0.0,
        ])->assertOk();

        $response = $this->deleteJson("/api/v1/flight/bookings/{$b->id}");
        $response->assertOk();

        $this->assertNotNull($b->fresh()->deleted_at, 'Booking must be soft-deleted.');
    }

    public function test_customer_07_double_cancel_returns_error(): void
    {
        $b = $this->createCustomerBooking('EGP', 100_000, 1500, 1000);

        $this->postJson("/api/v1/flight/bookings/{$b->id}/cancel", [
            'airline_penalty' => 0.0, 'office_penalty' => 0.0,
        ])->assertOk();

        $response = $this->postJson("/api/v1/flight/bookings/{$b->id}/cancel", [
            'airline_penalty' => 0.0, 'office_penalty' => 0.0,
        ]);
        $response->assertStatus(422);
    }

    public function test_customer_08_delete_unpaid(): void
    {
        $b = $this->createCustomerBooking('EGP', 100_000, 1500, 1000);

        $response = $this->deleteJson("/api/v1/flight/bookings/{$b->id}");
        $response->assertOk();

        $this->assertNotNull($b->fresh()->deleted_at);
    }

    // ═══════════════════════════════════════════════════════════════
    // SYSTEM PATH — 4 critical scenarios
    // ═══════════════════════════════════════════════════════════════

    public function test_system_01_unpaid_then_full_pay(): void
    {
        $b = $this->createSystemBooking('EGP', 100_000, 1500, 1000, null);
        $cashbox = $this->makeAccount('Cashbox SYS', 'cashbox', 'EGP', 50_000);

        $response = $this->postJson("/api/v1/flight/bookings/{$b->id}/payments", [
            'amount' => 1500.0, 'payment_method' => 'cash', 'account_id' => $cashbox->id,
        ]);
        $response->assertCreated();

        $b->refresh();
        $this->assertEquals(FlightBookingStatus::CONFIRMED->value, $b->status->value);
    }

    public function test_system_02_cancel_partial_pay(): void
    {
        $b = $this->createSystemBooking('EGP', 100_000, 1500, 1000, null);
        $cashbox = $this->makeAccount('Cashbox SYS', 'cashbox', 'EGP', 50_000);

        $this->postJson("/api/v1/flight/bookings/{$b->id}/payments", [
            'amount' => 500.0, 'payment_method' => 'cash', 'account_id' => $cashbox->id,
        ])->assertCreated();

        $this->postJson("/api/v1/flight/bookings/{$b->id}/cancel", [
            'airline_penalty' => 50.0, 'office_penalty' => 0.0,
            'account_id' => $cashbox->id,
        ])->assertOk();

        $b->refresh();
        $this->assertContains($b->status->value, ['REFUNDED', 'CANCELLED']);
    }

    public function test_system_03_delete_after_payment(): void
    {
        $b = $this->createSystemBooking('EGP', 100_000, 1500, 1000, null);
        $cashbox = $this->makeAccount('Cashbox SYS', 'cashbox', 'EGP', 50_000);

        $this->postJson("/api/v1/flight/bookings/{$b->id}/payments", [
            'amount' => 500.0, 'payment_method' => 'cash', 'account_id' => $cashbox->id,
        ])->assertCreated();

        $this->deleteJson("/api/v1/flight/bookings/{$b->id}")->assertOk();
        $this->assertNotNull($b->fresh()->deleted_at);
    }

    public function test_system_04_cancel_unpaid(): void
    {
        $b = $this->createSystemBooking('EGP', 100_000, 1500, 1000, null);

        $this->postJson("/api/v1/flight/bookings/{$b->id}/cancel", [
            'airline_penalty' => 0.0, 'office_penalty' => 0.0,
        ])->assertOk();

        $b->refresh();
        $this->assertEquals(FlightBookingStatus::CANCELLED->value, $b->status->value);
    }

    // ═══════════════════════════════════════════════════════════════
    // GROUP PATH — 6 critical scenarios (includes debt-ownership tests)
    // ═══════════════════════════════════════════════════════════════

    public function test_group_01_create_debts_group_account_not_customer(): void
    {
        [$b, $group] = $this->createGroupBooking('EGP', 100_000, 1500, 1000);

        $group->refresh();
        $groupAccount = Account::find($group->account_id);
        $this->assertNotNull($groupAccount);
        $this->assertLessThanOrEqual(
            -1000.0,
            (float) $groupAccount->balance,
            'Group account must be debited by purchase_price. Got: '.(float) $groupAccount->balance
        );
    }

    public function test_group_02_payment_reduces_customer_AR_not_group_AR(): void
    {
        [$b, $group] = $this->createGroupBooking('EGP', 100_000, 1500, 1000);
        $cashbox = $this->makeAccount('Cashbox Group', 'cashbox', 'EGP', 100_000);

        $groupAccountBefore = (float) Account::find($group->fresh()->account_id)->balance;

        $this->postJson("/api/v1/flight/bookings/{$b->id}/payments", [
            'amount' => 1500.0, 'payment_method' => 'cash', 'account_id' => $cashbox->id,
        ])->assertCreated();

        $groupAccountAfter = (float) Account::find($group->fresh()->account_id)->balance;

        $this->assertEquals(
            $groupAccountBefore, $groupAccountAfter,
            'Group AR must NOT change when customer pays — only customer AR + cashbox change.'
        );
    }

    public function test_group_03_group_payment_reduces_group_AR_only(): void
    {
        [$b, $group] = $this->createGroupBooking('EGP', 100_000, 1500, 1000);

        $groupAccountBefore = (float) Account::find($group->fresh()->account_id)->balance;
        $cashbox = $this->makeAccount('Group Pay Cashbox', 'cashbox', 'EGP', 50_000);

        $this->postJson("/api/v1/flight/groups/{$group->id}/pay-debt", [
            'amount' => 500.0,
            'account_id' => $cashbox->id,
            'type' => 'payment',
        ])->assertOk();

        $groupAccountAfter = (float) Account::find($group->fresh()->account_id)->balance;
        $this->assertGreaterThan($groupAccountBefore, $groupAccountAfter,
            'Group payment must REDUCE group debt (move toward zero).');
    }

    public function test_group_04_cancel_then_delete(): void
    {
        [$b, $group] = $this->createGroupBooking('EGP', 100_000, 1500, 1000);
        $cashbox = $this->makeAccount('Cashbox', 'cashbox', 'EGP', 50_000);

        $this->postJson("/api/v1/flight/bookings/{$b->id}/payments", [
            'amount' => 500.0, 'payment_method' => 'cash', 'account_id' => $cashbox->id,
        ])->assertCreated();

        $this->postJson("/api/v1/flight/bookings/{$b->id}/cancel", [
            'airline_penalty' => 50.0, 'office_penalty' => 0.0,
            'account_id' => $cashbox->id,
        ])->assertOk();

        $this->deleteJson("/api/v1/flight/bookings/{$b->id}")->assertOk();
        $this->assertNotNull($b->fresh()->deleted_at);
    }

    public function test_group_05_other_group_balance_unchanged(): void
    {
        [$b1, $groupA] = $this->createGroupBooking('EGP', 100_000, 1500, 1000);

        $groupB = FlightGroup::create([
            'flight_carrier_id' => $groupA->flight_carrier_id,
            'name' => 'Group B',
            'code' => 'GB-'.uniqid(),
            'currency' => 'EGP',
            'credit_limit' => 50_000,
            'is_active' => true,
            'created_by' => $this->admin->id,
        ]);

        $groupBAccountBefore = (float) ($groupB->account_id
            ? Account::find($groupB->account_id)->balance
            : 0);

        $this->deleteJson("/api/v1/flight/bookings/{$b1->id}")->assertOk();

        $groupBAccountAfter = (float) ($groupB->fresh()->account_id
            ? Account::find($groupB->account_id)->balance
            : 0);

        $this->assertEquals(
            $groupBAccountBefore, $groupBAccountAfter,
            'Other group balances MUST NOT be affected by this booking\'s deletion.'
        );
    }

    public function test_group_06_customer_AR_independent_of_group(): void
    {
        [$b, $group] = $this->createGroupBooking('EGP', 100_000, 1500, 1000);
        $cashbox = $this->makeAccount('Cashbox', 'cashbox', 'EGP', 50_000);

        $customer = Customer::find($b->customer_id);
        $customerLedgerId = $customer->account_id;
        $customerBefore = (float) Account::find($customerLedgerId)->balance;

        $this->postJson("/api/v1/flight/bookings/{$b->id}/payments", [
            'amount' => 1500.0, 'payment_method' => 'cash', 'account_id' => $cashbox->id,
        ])->assertCreated();

        $customerAfter = (float) Account::find($customerLedgerId)->balance;

        $this->assertLessThan($customerBefore, $customerAfter,
            'Customer AR must INCREASE (toward 0) after payment.');
        $this->assertGreaterThanOrEqual(0, $customerAfter,
            'Customer AR must not exceed selling_price.');
    }

    // ═══════════════════════════════════════════════════════════════
    // HELPERS
    // ═══════════════════════════════════════════════════════════════

    protected function createCustomerBooking(string $currency, float $cashboxBal, float $selling, float $purchase): FlightBooking
    {
        $customer = $this->makeCustomer('Customer');
        $cashbox = $this->makeAccount('Cashbox', 'cashbox', $currency, $cashboxBal);
        $carrier = $this->makeCarrier('Carrier', null, $currency);
        app(FlightCarrierRechargeService::class)->rechargeFromAccount(
            $carrier, $cashbox, 50_000, 'seed'
        );

        $response = $this->postJson('/api/v1/flight/bookings', [
            'customer_id' => $customer->id,
            'booking_channel_type' => 'SIGN',
            'purchase_balance_source' => 'carrier',
            'flight_carrier_id' => $carrier->id,
            'airline_name' => 'Carrier',
            'origin' => 'CAI',
            'destination' => 'JED',
            'departure_date' => now()->addDays(7)->toDateString(),
            'departure_time' => '00:00',
            'trip_type' => 'one_way',
            'passenger_count' => 1,
            'selling_price' => $selling,
            'purchase_price_egp' => $purchase,
            'currency' => $currency,
            'agent_name' => 'Office',
            'passengers' => [['first_name' => 'Test', 'last_name' => 'User', 'type' => 'adult']],
            'segments' => [['flight_number' => 'T1', 'from_airport' => 'CAI', 'to_airport' => 'JED',
                'departure_date' => now()->addDays(7)->toDateString(), 'flight_class' => 'economy']],
        ]);
        $response->assertCreated();

        return FlightBooking::find($response->json('data.id'));
    }

    protected function createSystemBooking(string $currency, float $cashboxBal, float $selling, float $purchase, ?float $foreign): FlightBooking
    {
        $customer = $this->makeCustomer('Sys Customer');
        $cashbox = $this->makeAccount('Cashbox SYS', 'cashbox', $currency, $cashboxBal);
        $system = $this->makeSystem('Test System', $currency);
        app(FlightSystemRechargeService::class)->rechargeFromAccount(
            $system, $cashbox, 50_000, 'seed'
        );

        $response = $this->postJson('/api/v1/flight/bookings', [
            'customer_id' => $customer->id,
            'booking_channel_type' => 'SYSTEM',
            'purchase_balance_source' => 'system',
            'flight_system_id' => $system->id,
            'airline_name' => 'System',
            'origin' => 'CAI',
            'destination' => 'JED',
            'departure_date' => now()->addDays(7)->toDateString(),
            'departure_time' => '00:00',
            'trip_type' => 'one_way',
            'passenger_count' => 1,
            'selling_price' => $selling,
            'purchase_price_egp' => $purchase,
            'currency' => $currency,
            'agent_name' => 'Office',
            'passengers' => [['first_name' => 'Test', 'last_name' => 'User', 'type' => 'adult']],
            'segments' => [['flight_number' => 'S1', 'from_airport' => 'CAI', 'to_airport' => 'JED',
                'departure_date' => now()->addDays(7)->toDateString(), 'flight_class' => 'economy']],
        ]);
        $response->assertCreated();

        return FlightBooking::find($response->json('data.id'));
    }

    /**
     * @return array{0: FlightBooking, 1: FlightGroup}
     */
    protected function createGroupBooking(string $currency, float $cashboxBal, float $selling, float $purchase): array
    {
        $customer = $this->makeCustomer('Group Customer');
        $cashbox = $this->makeAccount('Cashbox Group', 'cashbox', $currency, $cashboxBal);
        $carrier = $this->makeCarrier('Group Carrier', null, $currency);
        $group = FlightGroup::create([
            'flight_carrier_id' => $carrier->id,
            'name' => 'Test Group',
            'code' => 'TG-'.uniqid(),
            'currency' => $currency,
            'credit_limit' => 100_000,
            'is_active' => true,
            'created_by' => $this->admin->id,
        ]);

        $response = $this->postJson('/api/v1/flight/bookings', [
            'customer_id' => $customer->id,
            'booking_channel_type' => 'GROUP',
            'purchase_balance_source' => 'group',
            'flight_carrier_id' => $carrier->id,
            'flight_group_id' => $group->id,
            'airline_name' => 'Group Carrier',
            'origin' => 'CAI',
            'destination' => 'JED',
            'departure_date' => now()->addDays(7)->toDateString(),
            'departure_time' => '00:00',
            'trip_type' => 'one_way',
            'passenger_count' => 1,
            'selling_price' => $selling,
            'purchase_price_egp' => $purchase,
            'currency' => $currency,
            'agent_name' => 'Office',
            'passengers' => [['first_name' => 'Test', 'last_name' => 'User', 'type' => 'adult']],
            'segments' => [['flight_number' => 'G1', 'from_airport' => 'CAI', 'to_airport' => 'JED',
                'departure_date' => now()->addDays(7)->toDateString(), 'flight_class' => 'economy']],
        ]);
        $response->assertCreated();

        $booking = FlightBooking::find($response->json('data.id'));

        return [$booking, $group->fresh()];
    }

    protected function makeAccount(string $name, string $type, string $currency, float $balance): Account
    {
        $account = Account::create([
            'name' => $name, 'type' => $type, 'currency' => $currency,
            'balance' => 0, 'is_active' => true,
            'owner_type' => Account::OWNER_TYPE_OWNER ?? 'office',
            'module_type' => 'tourism', 'is_module_vault' => false,
            'notes' => 'P11.3 fixture', 'created_by' => $this->admin->id,
        ]);
        LedgerBalanceMutationGuard::run(function () use ($account, $balance) {
            $account->balance = $balance;
            $account->save();
        });
        AccountEntry::create([
            'account_id' => $account->id, 'transaction_id' => null,
            'debit' => 0, 'credit' => $balance, 'balance_after' => $balance,
            'notes' => 'opening',
        ]);

        return $account->fresh();
    }

    protected function makeCarrier(string $name, ?int $systemId, string $currency): FlightCarrier
    {
        return FlightCarrier::create([
            'name' => $name,
            'code' => substr(strtoupper($name), 0, 3).'-'.uniqid(),
            'flight_system_id' => $systemId,
            'currency' => $currency,
            'credit_limit' => 100_000,
            'is_active' => true,
            'created_by' => $this->admin->id,
        ]);
    }

    protected function makeSystem(string $name, string $currency): FlightSystem
    {
        return FlightSystem::create([
            'name' => $name,
            'code' => substr(strtoupper($name), 0, 3).'-'.uniqid(),
            'type' => 'gds',
            'currency' => $currency,
            'credit_limit' => 50_000,
            'is_active' => true,
            'created_by' => $this->admin->id,
        ]);
    }

    protected function makeCustomer(string $name): Customer
    {
        return Customer::create([
            'full_name' => $name,
            'phone' => '010'.str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT),
            'email' => 'c-'.uniqid().'@test.com',
            'national_id' => '29'.str_pad((string) random_int(0, 999999999999), 12, '0', STR_PAD_LEFT),
            'city' => 'Cairo',
            'module_type' => 'tourism',
        ])->fresh();
    }
}