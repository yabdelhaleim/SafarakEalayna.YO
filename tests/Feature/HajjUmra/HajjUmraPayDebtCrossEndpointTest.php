<?php

namespace Tests\Feature\HajjUmra;

use App\Enums\AccountType;
use App\Enums\TransactionModule;
use App\Models\Account;
use App\Models\AccountEntry;
use App\Models\Customer;
use App\Models\HajjUmraBooking;
use App\Models\HajjUmraPayment;
use App\Models\Program;
use App\Models\User;
use App\Services\Finance\TransactionService;
use App\Support\Finance\LedgerBalanceMutationGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Phase 10.10 — Cross-endpoint Pay-Debt / General-Receipt Coverage.
 *
 * Validates the customer's AR account's "general pass" path:
 *   - General receipts (journal entries on customer AR that are NOT tied to
 *     a specific booking payment) must appear in:
 *       (a) /api/v1/hajj-umra/customer-balances  (total_paid aggregate)
 *       (b) /api/v1/hajj-umra/customer-statement (as سند قبض line)
 *   - General receipts must NOT double-count when combined with booking
 *     payments on the same customer.
 *   - General receipts must SURVIVE the booking's delete-with-reversal
 *     (only the booking's ledger legs are reversed; the general receipt
 *     remains as a credit on the customer AR).
 *
 * These tests pin the contract that HajjUmraController::customerBalances()
 * and ::customerStatement() honor — they don't accidentally exclude the
 * general-receipt path that the field actually uses (cashier takes a
 * general collection via /customers screen, not via /hajj-umra/bookings/...).
 */
class HajjUmraPayDebtCrossEndpointTest extends TestCase
{
    use RefreshDatabase;

    private $admin;

    private $treasuryEGP;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::query()->create([
            'name' => 'PayDebt Admin',
            'email' => 'paydebt-admin-'.uniqid('', true).'@test.local',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);
        Sanctum::actingAs($this->admin, ['*']);

        $this->treasuryEGP = LedgerBalanceMutationGuard::run(function () {
            return Account::query()->create([
                'name' => 'PayDebt Treasury',
                'type' => AccountType::Cashbox->value,
                'currency' => 'EGP',
                'balance' => 1_000_000.00,
                'is_active' => true,
                'owner_type' => Account::OWNER_TYPE_OFFICE,
                'module_type' => 'tourism',
                'module' => 'hajj_umra',
                'is_module_vault' => true,
                'created_by' => $this->admin->id,
            ]);
        });
    }

    /* =========================================================
     *  SCENARIO 1: General receipt appears in customer_balances
     * ========================================================= */

    public function test_general_receipt_against_customer_ar_appears_in_customer_balances(): void
    {
        // Customer with a booking (50k sale, no payment yet — debt = 50000)
        $program = $this->makeProgram();
        $customer = $this->makeCustomer('PD-1 Customer');
        $booking = $this->createBooking($customer, $program, [
            'purchase_price' => 42000.0,
            'selling_price' => 50000.0,
        ]);

        // Pre-balance sanity: customer owes 50000
        $balanceBefore = $this->getCustomerBalance($customer);
        $this->assertEqualsWithDelta(50000.0, (float) ($balanceBefore['total_debt'] ?? 0.0), 0.01,
            'sanity: customer starts at debt=50000');

        // Cashier records a general receipt of 20000 (NOT via addPayment)
        $this->postGeneralReceipt($customer, 20000.0, 'PD1_GENERAL');

        $balanceAfter = $this->getCustomerBalance($customer);
        $this->assertEqualsWithDelta(30000.0, (float) ($balanceAfter['total_debt'] ?? 0.0), 0.01,
            'after general receipt of 20000: customer debt must drop to 30000');

        // Verify total_paid aggregate also reflects the general receipt
        $this->assertEqualsWithDelta(20000.0, (float) ($balanceAfter['total_paid'] ?? 0.0), 0.01,
            'customer_balances.total_paid must include the general receipt');

        $this->assertEqualsWithDelta(50000.0, (float) ($balanceAfter['total_sales'] ?? 0.0), 0.01,
            'customer_balances.total_sales must still be 50000');
    }

    /* =========================================================
     *  SCENARIO 2: General receipt appears in customer_statement
     * ========================================================= */

    public function test_general_receipt_appears_in_customer_statement_as_payment_line(): void
    {
        $program = $this->makeProgram();
        $customer = $this->makeCustomer('PD-2 Customer');
        $booking = $this->createBooking($customer, $program, [
            'purchase_price' => 42000.0,
            'selling_price' => 50000.0,
        ]);

        // General receipt 15000
        $this->postGeneralReceipt($customer, 15000.0, 'PD2_GENERAL');

        // GET customer statement
        $response = $this->getJson("/api/v1/hajj-umra/customer-statement?client_id={$customer->id}");
        $response->assertOk();

        $transactions = $response->json('data.transactions') ?? [];

        // The statement should have:
        //   - 1 invoice line for the booking (50000 debit)
        //   - 1 payment line for the general receipt (15000 credit) — this
        //     comes from the "general pass" of customerStatement()
        $types = array_column($transactions, 'type');
        $typeLabels = array_column($transactions, 'type_label');

        $this->assertContains('invoice', $types,
            'booking invoice must appear in statement');

        // The general receipt should appear as a 'payment' line with
        // type_label 'سند قبض' (Arabic for "receipt voucher"). Note the
        // pre-existing controller bug for cancelled/refunded bookings is
        // irrelevant here — booking is still 'confirmed'.
        $foundGeneralReceipt = false;
        foreach ($transactions as $line) {
            if (($line['type'] ?? '') === 'payment' && (float) ($line['credit'] ?? 0) === 15000.0) {
                $foundGeneralReceipt = true;
                $this->assertSame('سند قبض', $line['type_label'] ?? null,
                    'general receipt line must have type_label "سند قبض"');
                break;
            }
        }
        $this->assertTrue($foundGeneralReceipt,
            'general receipt of 15000 must appear in customer_statement as a payment line');
    }

    /* =========================================================
     *  SCENARIO 3: Combined general receipt + booking payment — no double-count
     * ========================================================= */

    public function test_general_receipt_then_add_payment_on_booking_does_not_double_count(): void
    {
        $program = $this->makeProgram();
        $customer = $this->makeCustomer('PD-3 Customer');
        $booking = $this->createBooking($customer, $program, [
            'purchase_price' => 42000.0,
            'selling_price' => 50000.0,
        ]);

        // 1000 via general receipt
        $this->postGeneralReceipt($customer, 1000.0, 'PD3_GENERAL');

        // 500 via booking payment
        $this->pay($booking, 500.0, 'PD3_BOOKING');

        $balance = $this->getCustomerBalance($customer);
        $this->assertEqualsWithDelta(48500.0, (float) ($balance['total_debt'] ?? 0.0), 0.01,
            'after general (1000) + booking payment (500): debt must be 48500');

        // total_paid must be 1500 (1000 general + 500 booking payment)
        $this->assertEqualsWithDelta(1500.0, (float) ($balance['total_paid'] ?? 0.0), 0.01,
            'total_paid must aggregate 1000 (general) + 500 (booking) = 1500, no double-count');
    }

    /* =========================================================
     *  SCENARIO 4: General receipt survives booking DELETE
     * ========================================================= */

    public function test_general_receipt_then_booking_delete_only_reverses_booking_leg_not_general(): void
    {
        $program = $this->makeProgram();
        $customer = $this->makeCustomer('PD-4 Customer');
        $booking = $this->createBooking($customer, $program, [
            'purchase_price' => 42000.0,
            'selling_price' => 50000.0,
        ]);

        // General receipt of 20000
        $this->postGeneralReceipt($customer, 20000.0, 'PD4_GENERAL');

        // DELETE the booking
        $this->deleteJson("/api/v1/hajj-umra/bookings/{$booking->id}")->assertOk();

        // The general receipt's credit on customer AR must SURVIVE.
        // - Original income (50000): reversed → -50000 on customer AR
        // - Original general receipt (20000): NOT reversed → -20000 on customer AR
        // Net customer AR = -20000 (creditor).
        $arAfter = $this->accountNet($customer->account_id);
        $this->assertEqualsWithDelta(-20000.0, $arAfter, 0.01,
            'after delete: customer AR must show -20000 (the surviving general receipt credit)');

        // The customer_balances view excludes the cancelled booking (now
        // soft-deleted) so it returns debt=0. The general receipt is NOT a
        // booking row, so it's not visible in the booking aggregate; it only
        // surfaces as the customer AR account balance.
        $balance = $this->getCustomerBalance($customer);
        $this->assertEqualsWithDelta(0.0, (float) ($balance['total_debt'] ?? 0.0), 0.01,
            'after delete: customer_balances shows 0 (soft-deleted booking excluded; '.
            'general receipt is not represented in the view)');

        $this->assertLedgerGloballyBalanced();
    }

    /* =========================================================
     *  HELPERS
     * ========================================================= */

    private function makeCustomer(string $name): Customer
    {
        return Customer::query()->create([
            'full_name' => $name,
            'phone' => '010'.substr((string) mt_rand(10_000_000, 99_999_999), 0, 8),
            'email' => 'pd-cust-'.uniqid('', true).'@test.local',
            'is_active' => true,
        ]);
    }

    private function makeProgram(): Program
    {
        return Program::query()->create([
            'program_name' => 'PD Program '.uniqid(),
            'program_type' => 'hajj',
            'total_nights' => 14,
            'mecca_nights' => 8,
            'medina_nights' => 6,
            'accommodation_type' => 'DOUBLE',
            'mecca_hotel_name' => 'فندق مكة',
            'medina_hotel_name' => 'فندق المدينة',
            'departure_date' => now()->addDays(60)->toDateString(),
            'return_date' => now()->addDays(74)->toDateString(),
            'airline' => 'Test Air',
            'executing_company' => 'PD EC',
            'departure_point' => 'CAI',
            'default_selling_price' => 50000.00,
            'default_purchase_price' => 42000.00,
            'is_active' => true,
            'created_by' => $this->admin->id,
        ]);
    }

    private function createBooking(Customer $customer, Program $program, array $overrides = []): HajjUmraBooking
    {
        $payload = array_merge([
            'customer' => [
                'full_name' => $customer->full_name,
                'phone' => $customer->phone,
            ],
            'program_id' => $program->id,
            'purchase_price' => 42000.0,
            'selling_price' => 50000.0,
            'currency' => 'EGP',
            'account_id' => $this->treasuryEGP->id,
        ], $overrides);

        $response = $this->postJson('/api/v1/hajj-umra/bookings', $payload);
        $response->assertCreated();

        return HajjUmraBooking::findOrFail($response->json('data.id'));
    }

    private function pay(HajjUmraBooking $booking, float $amount, string $idemKey): HajjUmraPayment
    {
        $response = $this->postJson("/api/v1/hajj-umra/bookings/{$booking->id}/payments", [
            'amount' => $amount,
            'payment_method' => 'cash',
            'account_id' => $this->treasuryEGP->id,
            'idempotency_key' => $idemKey.'_'.uniqid(),
        ]);
        $response->assertCreated();

        return HajjUmraPayment::findOrFail($response->json('data.payment.id'));
    }

    private function postGeneralReceipt(Customer $customer, float $amount, string $note): void
    {
        $customer->refresh();
        $customerAccountId = (int) $customer->account_id;

        if (! $customerAccountId) {
            $account = Account::query()->create([
                'name' => 'PD Customer AR: '.$customer->full_name,
                'type' => AccountType::Customer->value,
                'currency' => 'EGP',
                'balance' => 0.00,
                'is_active' => true,
                'owner_type' => Account::OWNER_TYPE_OWNER,
                'module_type' => 'hajj_umra',
                'created_by' => $this->admin->id,
            ]);
            $customer->update(['account_id' => $account->id]);
            $customerAccountId = $account->id;
        }

        app(TransactionService::class)->recordJournalTransfer([
            'from_account_id' => $customerAccountId,
            'to_account_id' => $this->treasuryEGP->id,
            'amount' => $amount,
            'currency' => 'EGP',
            'module' => TransactionModule::HajjUmra->value,
            'type' => 'transfer',
            'notes' => $note,
            'created_by' => $this->admin->id,
        ]);
    }

    private function getCustomerBalance(Customer $customer): array
    {
        $response = $this->getJson('/api/v1/hajj-umra/customer-balances');
        $response->assertOk();
        foreach (($response->json('data') ?? []) as $row) {
            if ((int) ($row['client_id'] ?? 0) === $customer->id) {
                return $row;
            }
        }

        return [];
    }

    private function accountNet(int $accountId): float
    {
        $row = AccountEntry::where('account_id', $accountId)
            ->selectRaw('SUM(credit) - SUM(debit) as net')
            ->first();

        return (float) ($row->net ?? 0.0);
    }

    private function assertLedgerGloballyBalanced(): void
    {
        $totalCredit = (float) AccountEntry::query()->sum('credit');
        $totalDebit = (float) AccountEntry::query()->sum('debit');
        $this->assertEqualsWithDelta(
            $totalCredit, $totalDebit, 0.01,
            "ledger must be globally balanced: credit=$totalCredit debit=$totalDebit"
        );
    }
}
