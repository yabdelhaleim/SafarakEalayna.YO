<?php

namespace Tests\Feature;

use App\Enums\BusInventoryPaymentType;
use App\Models\Account;
use App\Models\Bus\BusBooking;
use App\Models\Bus\BusCompany;
use App\Models\Bus\BusInventory;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BusBookingFlowTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Account $account;

    protected BusInventory $inventory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::query()->create([
            'name' => 'Bus API Tester',
            'email' => 'bus-api-test@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        Employee::query()->create([
            'user_id' => $this->user->id,
            'status' => 'active',
        ]);

        $this->account = Account::query()->create([
            'name' => 'Test cashbox',
            'type' => 'cashbox',
            'currency' => 'EGP',
            'balance' => 5000,
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'bus',
            'module' => 'bus',
            'created_by' => $this->user->id,
        ]);

        $company = BusCompany::query()->create([
            'name' => 'Test Bus Co',
            'phone' => '01000000000',
            'is_active' => true,
            'created_by' => $this->user->id,
        ]);

        $this->inventory = BusInventory::query()->create([
            'company_id' => $company->id,
            'route' => 'Cairo - Alexandria',
            'travel_date' => now()->addDay()->toDateString(),
            'total_tickets' => 10,
            'available_tickets' => 10,
            'cost_per_ticket' => 50,
            'selling_price' => 100,
            'payment_type' => BusInventoryPaymentType::Deferred,
            'total_cost' => 500,
            'amount_paid' => 0,
            'remaining_debt' => 500,
            'created_by' => $this->user->id,
        ]);

        Sanctum::actingAs($this->user, ['*']);
    }

    public function test_can_cancel_credit_booking_without_payment(): void
    {
        $create = $this->postJson('/api/v1/bus/bookings', [
            'inventory_id' => $this->inventory->id,
            'customer_name' => 'عميل تجريبي',
            'customer_phone' => '01001112233',
            'quantity' => 1,
        ]);

        $create->assertCreated();
        $bookingId = $create->json('data.id');
        $this->assertNotNull($bookingId);

        $cancel = $this->postJson("/api/v1/bus/bookings/{$bookingId}/cancel", [
            'company_penalty' => 0,
            'office_penalty' => 0,
        ]);
        $cancel->assertOk();
        $cancel->assertJsonPath('data.status', 'cancelled');
    }

    public function test_cancel_credit_booking_with_penalties_adjusts_company_and_customer_debt(): void
    {
        $company = $this->inventory->company()->with('account')->first();
        app(\App\Services\Bus\BusCompanyService::class)->ensureCompanyAccount($company);
        $company->refresh();

        $create = $this->postJson('/api/v1/bus/bookings', [
            'inventory_id' => $this->inventory->id,
            'customer_name' => 'عميل آجل',
            'customer_phone' => '01005556677',
            'quantity' => 2,
        ]);
        $create->assertCreated();
        $bookingId = $create->json('data.id');

        $companyBalanceBefore = (float) $company->fresh()->account->balance;
        $this->assertEquals(-100.0, $companyBalanceBefore);

        $cancel = $this->postJson("/api/v1/bus/bookings/{$bookingId}/cancel", [
            'company_penalty' => 20,
            'office_penalty' => 30,
        ]);
        $cancel->assertOk();
        $cancel->assertJsonPath('data.status', 'partially_refunded');
        $cancel->assertJsonPath('data.refund.company_penalty', 20);
        $cancel->assertJsonPath('data.refund.office_penalty', 30);
        $cancel->assertJsonPath('data.refund.refund_amount', 0);

        $company->refresh();
        // كان -100، يُخفَّض بـ (100 - 20) = 80 → يصبح -20
        $this->assertEquals(-20.0, (float) $company->account->balance);

        $this->assertDatabaseHas('bus_refund_requests', [
            'bus_booking_id' => $bookingId,
            'company_penalty' => 20,
            'office_penalty' => 30,
            'refund_amount' => 0,
            'status' => 'processed',
        ]);
    }

    public function test_cancel_paid_booking_refunds_customer_cash_minus_penalties(): void
    {
        $create = $this->postJson('/api/v1/bus/bookings', [
            'inventory_id' => $this->inventory->id,
            'customer_name' => 'عميل دافع',
            'customer_phone' => '01006667788',
            'quantity' => 1,
        ]);
        $create->assertCreated();
        $bookingId = $create->json('data.id');

        $pay = $this->postJson("/api/v1/bus/bookings/{$bookingId}/pay", [
            'amount' => 100,
            'payment_method' => 'cash',
            'account_id' => $this->account->id,
        ]);
        $pay->assertOk();

        $treasuryBefore = (float) $this->account->fresh()->balance;

        $cancel = $this->postJson("/api/v1/bus/bookings/{$bookingId}/cancel", [
            'company_penalty' => 10,
            'office_penalty' => 15,
            'account_id' => $this->account->id,
        ]);
        $cancel->assertOk();
        $cancel->assertJsonPath('data.status', 'refunded');
        $cancel->assertJsonPath('data.refund.refund_amount', 75);

        $this->account->refresh();
        // دفع 100 ثم استرداد 75
        $this->assertEquals($treasuryBefore - 75.0, (float) $this->account->balance);
    }

    public function test_payment_amount_cannot_exceed_remaining(): void
    {
        $create = $this->postJson('/api/v1/bus/bookings', [
            'inventory_id' => $this->inventory->id,
            'customer_name' => 'عميل جزئي',
            'customer_phone' => '01003334455',
            'quantity' => 1,
        ]);
        $create->assertCreated();
        $bookingId = $create->json('data.id');

        $over = $this->postJson("/api/v1/bus/bookings/{$bookingId}/pay", [
            'amount' => 150,
            'payment_method' => 'cash',
            'account_id' => $this->account->id,
        ]);
        $over->assertStatus(422);

        $partial = $this->postJson("/api/v1/bus/bookings/{$bookingId}/pay", [
            'amount' => 40,
            'payment_method' => 'cash',
            'account_id' => $this->account->id,
        ]);
        $partial->assertOk();

        $rest = $this->postJson("/api/v1/bus/bookings/{$bookingId}/pay", [
            'amount' => 60,
            'payment_method' => 'cash',
            'account_id' => $this->account->id,
        ]);
        $rest->assertOk();
        $rest->assertJsonPath('data.status', 'paid');

        $this->assertSame(100.0, (float) BusBooking::query()->findOrFail($bookingId)->paid_amount);
    }

    public function test_booking_creation_automatically_provisions_and_links_company_account_and_updates_balances(): void
    {
        // 1. Create a company without an account_id
        $companyWithoutAccount = BusCompany::query()->create([
            'name' => 'Legacy Company Without Account',
            'phone' => '01009999999',
            'is_active' => true,
            'created_by' => $this->user->id,
        ]);

        $this->assertNull($companyWithoutAccount->account_id);

        // Create inventory linked to this company
        $tempInventory = BusInventory::query()->create([
            'company_id' => $companyWithoutAccount->id,
            'route' => 'Cairo - Giza',
            'travel_date' => now()->addDay()->toDateString(),
            'total_tickets' => 5,
            'available_tickets' => 5,
            'cost_per_ticket' => 60,
            'selling_price' => 120,
            'payment_type' => BusInventoryPaymentType::Deferred,
            'total_cost' => 300,
            'amount_paid' => 0,
            'remaining_debt' => 300,
            'created_by' => $this->user->id,
        ]);

        // 2. Fetch the dashboard stats initially
        $dashResBefore = $this->getJson('/api/v1/bus/dashboard');
        $dashResBefore->assertOk();
        $initialDebt = (float) ($dashResBefore->json('data.total_company_debt') ?? 0);
        $initialRevenue = (float) ($dashResBefore->json('data.stats.monthly_revenue') ?? 0);
        $initialBookings = (int) ($dashResBefore->json('data.stats.total_bookings') ?? 0);

        // 3. Create a booking on this inventory
        $bookingResponse = $this->postJson('/api/v1/bus/bookings', [
            'inventory_id' => $tempInventory->id,
            'customer_name' => 'عميل حجز تلقائي',
            'customer_phone' => '01009998877',
            'quantity' => 2,
        ]);
        $bookingResponse->assertCreated();

        // 4. Assert that the company now has a linked ledger account
        $companyWithoutAccount->refresh();
        $this->assertNotNull($companyWithoutAccount->account_id);
        $this->assertNotNull($companyWithoutAccount->account);
        $this->assertEquals('supplier', $companyWithoutAccount->account->type->value);

        // 5. Assert the company balance reflects the cost-price debt (goes negative)
        // 2 tickets * 60 cost = 120 cost. Since we owe 120, balance is -120.00.
        $this->assertEquals(-120.00, (float) $companyWithoutAccount->account->balance);

        // 6. Verify Dashboard stats updated
        $dashResAfter = $this->getJson('/api/v1/bus/dashboard');
        $dashResAfter->assertOk();
        $this->assertEquals($initialBookings + 1, $dashResAfter->json('data.stats.total_bookings'));
        $this->assertEquals($initialRevenue + 240.00, (float) $dashResAfter->json('data.stats.monthly_revenue'));
        $this->assertEquals($initialDebt + 120.00, (float) $dashResAfter->json('data.total_company_debt'));

        // 7. Verify current debt widget via Company Show API
        $companyShowRes = $this->getJson("/api/v1/bus/companies/{$companyWithoutAccount->id}");
        $companyShowRes->assertOk();
        $companyShowRes->assertJsonPath('data.account_id', $companyWithoutAccount->account_id);
        $this->assertEquals(-120.00, (float) $companyShowRes->json('data.balance'));
        $companyShowRes->assertJsonStructure([
            'data' => [
                'account' => ['id', 'name', 'balance']
            ]
        ]);

        // 8. Test paying the company debt
        // Create a treasury account with enough balance to pay the company
        $treasuryAccount = Account::query()->create([
            'name' => 'Safe box',
            'type' => 'cashbox',
            'currency' => 'EGP',
            'balance' => 500.00,
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'bus',
            'module' => 'bus',
            'created_by' => $this->user->id,
        ]);

        $payDebtResponse = $this->postJson("/api/v1/bus/companies/{$companyWithoutAccount->id}/pay-debt", [
            'amount' => 50.00,
            'from_account_id' => $treasuryAccount->id,
            'notes' => 'تسديد دفعة',
        ]);
        $payDebtResponse->assertOk();

        // 9. Assert balances are updated correctly in both accounts
        $treasuryAccount->refresh();
        $companyWithoutAccount->refresh();
        $this->assertEquals(450.00, (float) $treasuryAccount->balance); // 500 - 50 = 450
        $this->assertEquals(-70.00, (float) $companyWithoutAccount->account->balance); // -120 + 50 = -70

        // 10. Assert BusCompanyPayment was created
        $this->assertDatabaseHas('bus_company_payments', [
            'company_id' => $companyWithoutAccount->id,
            'amount' => 50.00,
            'account_id' => $treasuryAccount->id,
            'status' => 'paid',
        ]);
    }

    public function test_booking_creation_fails_and_rolls_back_if_exception_thrown(): void
    {
        $initialAvailableTickets = $this->inventory->available_tickets;

        // Try to create booking with quantity exceeding available tickets
        $response = $this->postJson('/api/v1/bus/bookings', [
            'inventory_id' => $this->inventory->id,
            'customer_name' => 'Rollback Test Customer',
            'customer_phone' => '01009998877',
            'quantity' => 100, // Exceeds available tickets (10)
        ]);

        $response->assertStatus(422);

        // Verify that available tickets were not decremented
        $this->inventory->refresh();
        $this->assertEquals($initialAvailableTickets, $this->inventory->available_tickets);

        // Verify no booking was created in the database
        $this->assertDatabaseMissing('bus_bookings', [
            'quantity' => 100,
        ]);
    }

    /**
     * 🔴 ACCOUNTING INTEGRITY: Cancel after payDebt must be BLOCKED.
     *
     * Scenario:
     *   1. createBooking  → company balance: -100 (we owe them)
     *   2. payDebt(100)   → company balance:    0 (debt cleared)
     *   3. cancelBooking  → MUST THROW, NOT credit company +100 again
     *
     * If allowed, company balance would be +100 (phantom receivable),
     * and 100 EGP would silently vanish from the treasury.
     */
    public function test_cancel_booking_after_pay_debt_is_blocked_to_prevent_accounting_fraud(): void
    {
        // 1. Create a booking (company gets debt: -100 balance)
        $bookingRes = $this->postJson('/api/v1/bus/bookings', [
            'inventory_id' => $this->inventory->id,
            'customer_name' => 'عميل إلغاء بعد دفع',
            'customer_phone' => '01011223344',
            'quantity' => 2, // 2 × 50 cost = 100
        ]);
        $bookingRes->assertCreated();
        $bookingId = $bookingRes->json('data.id');

        // Confirm company balance is -100
        $company = $this->inventory->company()->with('account')->first();
        $this->assertEquals(-100.0, (float) $company->account->balance);

        // 2. Pay the full debt (treasury → company account)
        $treasury = Account::query()->create([
            'name'       => 'Treasury for test',
            'type'       => 'cashbox',
            'currency'   => 'EGP',
            'balance'    => 500.0,
            'is_active'  => true,
            'owner_type' => 'office',
            'module_type' => 'bus',
            'module' => 'bus',
            'created_by' => $this->user->id,
        ]);

        $payRes = $this->postJson("/api/v1/bus/companies/{$company->id}/pay-debt", [
            'amount'          => 100.0,
            'from_account_id' => $treasury->id,
            'notes'           => 'تسديد كامل',
        ]);
        $payRes->assertOk();

        // Confirm company balance is now 0
        $company->refresh();
        $this->assertEquals(0.0, (float) $company->account->balance);

        // Confirm treasury is -100
        $treasury->refresh();
        $this->assertEquals(400.0, (float) $treasury->balance);

        // 3. Now try to cancel the booking — MUST BE BLOCKED
        $cancelRes = $this->postJson("/api/v1/bus/bookings/{$bookingId}/cancel");
        $cancelRes->assertStatus(422);

        // Verify the error message mentions debt settlement
        $this->assertStringContainsString('تسديده', $cancelRes->json('message'));

        // 🔑 CRITICAL: Company balance must NOT have changed
        $company->refresh();
        $this->assertEquals(0.0, (float) $company->account->balance,
            'Company account balance must stay 0 — not become +100 (phantom receivable)'
        );

        // Treasury must also be unchanged after blocked cancel
        $treasury->refresh();
        $this->assertEquals(400.0, (float) $treasury->balance,
            'Treasury must not gain or lose money from a blocked cancellation'
        );
    }

    /**
     * 🔴 ACCOUNTING INTEGRITY: payDebt must reject overpayment.
     *
     * Scenario: Debt is 50, but staff tries to pay 200.
     * Must reject with a clear error — not allow overpayment.
     */
    public function test_pay_debt_rejects_amount_exceeding_actual_company_debt(): void
    {
        // 1. Create a booking → company debt: -50 (1 ticket × 50 cost)
        $bookingRes = $this->postJson('/api/v1/bus/bookings', [
            'inventory_id' => $this->inventory->id,
            'customer_name' => 'عميل دفع زائد',
            'customer_phone' => '01044556677',
            'quantity' => 1, // 1 × 50 cost = 50
        ]);
        $bookingRes->assertCreated();

        $company = $this->inventory->company()->with('account')->first();
        $this->assertEquals(-50.0, (float) $company->account->balance);

        $treasury = Account::query()->create([
            'name'       => 'Treasury overpay test',
            'type'       => 'cashbox',
            'currency'   => 'EGP',
            'balance'    => 500.0,
            'is_active'  => true,
            'owner_type' => 'office',
            'module_type' => 'bus',
            'module' => 'bus',
            'created_by' => $this->user->id,
        ]);

        // 2. Try to overpay (200 > 50 debt) — MUST BE REJECTED
        $overpayRes = $this->postJson("/api/v1/bus/companies/{$company->id}/pay-debt", [
            'amount'          => 200.0,
            'from_account_id' => $treasury->id,
        ]);
        $overpayRes->assertStatus(422);
        $this->assertStringContainsString('يتجاوز', $overpayRes->json('message'));

        // Balances must be unchanged
        $treasury->refresh();
        $company->refresh();
        $this->assertEquals(500.0, (float) $treasury->balance);
        $this->assertEquals(-50.0, (float) $company->account->balance);

        // 3. Correct amount pays fine
        $correctRes = $this->postJson("/api/v1/bus/companies/{$company->id}/pay-debt", [
            'amount'          => 50.0,
            'from_account_id' => $treasury->id,
        ]);
        $correctRes->assertOk();
        $correctRes->assertJsonPath('data.fully_settled', true);

        $treasury->refresh();
        $company->refresh();
        $this->assertEquals(450.0, (float) $treasury->balance);
        $this->assertEquals(0.0, (float) $company->account->balance);
    }

    /**
     * 🔴 ACCOUNTING INTEGRITY: payDebt must reject when no debt exists.
     */
    public function test_pay_debt_rejects_when_company_has_no_debt(): void
    {
        // Company without any bookings → no debt (balance = 0 or positive)
        $company = $this->inventory->company()->with('account')->first();

        // Ensure account exists but has zero balance
        app(\App\Services\Bus\BusCompanyService::class)->ensureCompanyAccount($company);
        $company->refresh();

        $treasury = Account::query()->create([
            'name'       => 'Treasury zero debt test',
            'type'       => 'cashbox',
            'currency'   => 'EGP',
            'balance'    => 500.0,
            'is_active'  => true,
            'owner_type' => 'office',
            'module_type' => 'bus',
            'module' => 'bus',
            'created_by' => $this->user->id,
        ]);

        // Try to pay even though there's no debt
        $res = $this->postJson("/api/v1/bus/companies/{$company->id}/pay-debt", [
            'amount'          => 100.0,
            'from_account_id' => $treasury->id,
        ]);
        $res->assertStatus(422);
        $this->assertStringContainsString('لا يوجد دين', $res->json('message'));

        // Treasury and company balance must remain unchanged
        $treasury->refresh();
        $this->assertEquals(500.0, (float) $treasury->balance);
    }
}
