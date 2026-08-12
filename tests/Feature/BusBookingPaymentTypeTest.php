<?php

namespace Tests\Feature;

use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\Bus\BusBooking;
use App\Models\Bus\BusCompany;
use App\Models\Bus\BusInventory;
use App\Services\Bus\BusBookingService;
use App\Services\Finance\TransactionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Regression test for the duplicate income transaction bug.
 *
 * Before the fix (commit fixing payBooking on 2026-08-12):
 *   - createBooking() registered a SALE income tx
 *   - payBooking() registered a PAYMENT income tx (BUG)
 *   - Total: 2 income tx per booking → income sum doubled.
 *
 * After the fix:
 *   - createBooking() still registers SALE income tx
 *   - payBooking() registers PAYMENT transfer tx
 *   - Total: 1 income tx + 1 transfer tx per booking.
 *
 * This test verifies the post-fix behavior and ensures the regression cannot
 * reoccur silently.
 */
class BusBookingPaymentTypeTest extends TestCase
{
    use RefreshDatabase;

    protected $user;

    protected $account;

    protected $inventory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = \App\Models\User::query()->create([
            'name' => 'Regression Tester',
            'email' => 'regression-'.uniqid().'@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        \App\Models\Employee::query()->create([
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
            'module_type' => 'office',
            'module' => 'office',
        ]);

        $company = BusCompany::query()->create([
            'name' => 'Test Company',
            'currency' => 'EGP',
            'is_active' => true,
            'created_by' => $this->user->id,
        ]);

        // Auto-create bus clearing accounts (income + expense) so the service runs
        $service = app(\App\Services\Finance\LedgerClearingAccounts::class);
        $service->incomeContraIdForModule('bus');
        $service->expenseContraIdForModule('bus');

        $this->inventory = BusInventory::query()->create([
            'company_id' => $company->id,
            'route' => 'Cairo-Alex',
            'travel_date' => now()->addDays(7),
            'departure_time' => '08:00',
            'selling_price' => 400,
            'cost_per_ticket' => 300,
            'total_tickets' => 10,
            'available_tickets' => 10,
            'currency' => 'EGP',
            'exchange_rate_to_egp' => 1,
            'payment_type' => BusInventoryPaymentType::Cash,
            'is_active' => true,
            'created_by' => $this->user->id,
        ]);

        $this->actingAs($this->user);
    }

    /** @test */
    public function payBooking_must_record_transfer_not_income()
    {
        $service = app(BusBookingService::class);

        // 1. Create a booking (this writes the SALE income tx)
        $booking = $service->createBooking([
            'inventory_id' => $this->inventory->id,
            'quantity' => 1,
            'unit_price' => 400,
            'total_price' => 400,
            'currency' => 'EGP',
            'exchange_rate_to_egp' => 1,
            'customer_name' => 'Test Customer',
            'customer_phone' => '01000000000',
            'notes' => 'regression test',
            'account_id' => $this->account->id,
        ]);

        $saleTx = \App\Models\Transaction::query()
            ->where('related_type', BusBooking::class)
            ->where('related_id', $booking->id)
            ->where('type', TransactionType::Income->value)
            ->first();

        $this->assertNotNull($saleTx, 'createBooking should register a SALE income tx');

        // 2. Pay the booking — this is the critical assertion
        $service->payBooking($booking, [
            'amount' => 400,
            'account_id' => $this->account->id,
            'payment_method' => 'cash',
            'notes' => 'regression payment',
        ]);

        // 3. Verify: the payment tx must be a TRANSFER, not an INCOME
        $paymentTx = \App\Models\Transaction::query()
            ->where('related_type', BusBooking::class)
            ->where('related_id', $booking->id)
            ->where('type', TransactionType::Transfer->value)
            ->first();

        $this->assertNotNull($paymentTx, 'payBooking should register a TRANSFER tx');

        $postFixIncomeCount = \App\Models\Transaction::query()
            ->where('related_type', BusBooking::class)
            ->where('related_id', $booking->id)
            ->where('type', TransactionType::Income->value)
            ->count();

        $this->assertEquals(
            1,
            $postFixIncomeCount,
            'After payBooking, there should be exactly ONE income tx (the sale), not two.'
        );
    }

    /** @test */
    public function duplicate_income_transaction_is_rejected_by_service_guard()
    {
        $service = app(BusBookingService::class);

        $booking = $service->createBooking([
            'inventory_id' => $this->inventory->id,
            'quantity' => 1,
            'unit_price' => 400,
            'total_price' => 400,
            'currency' => 'EGP',
            'exchange_rate_to_egp' => 1,
            'customer_name' => 'Test Customer',
            'customer_phone' => '01000000001',
            'notes' => 'guard test',
            'account_id' => $this->account->id,
        ]);

        // Try to manually create a DUPLICATE income tx for the same booking.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/duplicate income/i');

        app(TransactionService::class)->recordJournalTransfer([
            'amount' => 400,
            'from_account_id' => $this->account->id,
            'to_account_id' => $this->account->id + 1,
            'module' => 'bus',
            'type' => TransactionType::Income->value,
            'related_type' => BusBooking::class,
            'related_id' => $booking->id,
            'notes' => 'should be blocked',
            'created_by' => $this->user->id,
        ]);
    }
}
