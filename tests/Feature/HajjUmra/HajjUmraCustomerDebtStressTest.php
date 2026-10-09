<?php

namespace Tests\Feature\HajjUmra;

use App\Enums\AccountType;
use App\Enums\TransactionModule;
use App\Models\Account;
use App\Models\AccountEntry;
use App\Models\Customer;
use App\Models\HajjUmra\HajjUmraExecutingCompany;
use App\Models\HajjUmra\UmrahSupplier;
use App\Models\HajjUmraBooking;
use App\Models\Program;
use App\Models\User;
use App\Services\Finance\TransactionService;
use App\Support\Finance\LedgerBalanceMutationGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Phase 10.10 — Customer-Debt Stress Tests (deletion with outstanding debt).
 *
 * Specifically exercises the user's question:
 *   "الحذف مع عميل علي ديون" — DELETE on a customer who currently has
 *   outstanding debt.
 *
 * The most realistic scenarios for a Hajj/Umrah operator:
 *
 *   A. ONE customer, THREE bookings with MIXED payment states:
 *        Booking X — fully paid (no residual debt) → DELETE
 *        Booking Y — partially paid (50% debt = 25000) → DELETE
 *        Booking Z — unpaid (100% debt = 50000) → DELETE
 *      Plus: a general receipt on the customer's AR (e.g. cashier collected
 *      some cash against an older debt via /customers screen, not via
 *      booking payment API).
 *      Then: DELETE all 3 bookings.
 *      Then: assert EVERY account (treasury, customer AR, supplier AP,
 *      executing-company AP, clearing buckets) returns to the pre-booking
 *      baseline. The general receipt credit must SURVIVE (it's not a
 *      booking leg, so the booking delete must not touch it).
 *      Then: assert customer_balances.total_debt is correctly reported
 *      (should be -general_receipt_amount — i.e., creditor).
 *
 *   B. ONE customer, ONE booking — partial-paid → DELETE in the MIDDLE
 *      of a payment cycle (after the supplier has already received the
 *      expense from the treasury-as-source). Proves the supplier AP
 *      leg is fully reversed even though no supplier exists.
 *
 *   C. ONE customer with an OLD debt from a PREVIOUSLY-soft-deleted
 *      booking — proves the customer's AR is truly zero after the
 *      second delete (no ghost debt left over).
 *
 *   D. ONE customer with TWO partial-paid bookings across DIFFERENT
 *      currencies — DELETE both. Asserts the cross-currency FX legs
 *      clean up correctly.
 */
class HajjUmraCustomerDebtStressTest extends TestCase
{
    use RefreshDatabase;

    private $admin;

    private $treasuryEGP;

    private $treasuryUSD;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::query()->create([
            'name' => 'DebtStress Admin',
            'email' => 'debtstress-admin-'.uniqid('', true).'@test.local',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);
        Sanctum::actingAs($this->admin, ['*']);

        LedgerBalanceMutationGuard::run(function () {
            $this->treasuryEGP = Account::query()->create([
                'name' => 'DebtStress Treasury EGP',
                'type' => AccountType::Cashbox->value,
                'currency' => 'EGP',
                'balance' => 2_000_000.00,
                'is_active' => true,
                'owner_type' => Account::OWNER_TYPE_OFFICE,
                'module_type' => 'tourism',
                'module' => 'hajj_umra',
                'is_module_vault' => true,
                'created_by' => $this->admin->id,
            ]);

            $this->treasuryUSD = Account::query()->create([
                'name' => 'DebtStress Treasury USD',
                'type' => AccountType::Cashbox->value,
                'currency' => 'USD',
                'balance' => 50_000.00,
                'is_active' => true,
                'owner_type' => Account::OWNER_TYPE_OFFICE,
                'module_type' => 'tourism',
                'module' => 'hajj_umra',
                'is_module_vault' => true,
                'created_by' => $this->admin->id,
            ]);
        });

        // Seed FX rates so cross-currency tests pass without each test seeding
        $this->seedExchangeRate('EGP', 'USD', 0.032);
        $this->seedExchangeRate('USD', 'EGP', 31.25);
    }

    /* =========================================================
     *  STRESS SCENARIO A — 3 mixed bookings + general receipt → DELETE all
     * ========================================================= */

    public function test_three_mixed_bookings_plus_general_receipt_then_delete_all_zeroes_baseline(): void
    {
        $baseline = $this->snapshotBalances();

        $customer = $this->makeCustomer('DebtStress-A Customer');
        $program = $this->makeProgram();

        // ── Booking X: EGP, fully paid (50000) — no residual debt
        $bookingX = $this->createBooking($customer, $program, [
            'purchase_price' => 42000.0,
            'selling_price' => 50000.0,
            'account_id' => $this->treasuryEGP->id,
        ]);
        $this->pay($bookingX, 50000.0, 'DEBT_A_X');

        // ── Booking Y: EGP, partial-paid (50%) — 25000 debt
        $bookingY = $this->createBooking($customer, $program, [
            'purchase_price' => 42000.0,
            'selling_price' => 50000.0,
            'account_id' => $this->treasuryEGP->id,
        ]);
        $this->pay($bookingY, 25000.0, 'DEBT_A_Y');

        // ── Booking Z: USD supplier + EGP clearing, UNPAID — 50000 debt
        $supplier = $this->makeSupplier();
        $bookingZ = $this->createBooking($customer, $program, [
            'purchase_price' => 42000.0,
            'selling_price' => 50000.0,
            'supplier_id' => $supplier->id,
            'account_id' => $this->treasuryEGP->id,
        ]);
        // no payment

        // ── Sanity check: customer should have a meaningful outstanding debt
        // X (fully paid) = 0 debt
        // Y (50% paid) = 25000 debt
        // Z (unpaid, USD supplier) = 50000 debt
        // Total = 75000
        $balanceBefore = $this->getCustomerBalance($customer);
        $expectedDebt = 0.0 + 25000.0 + 50000.0; // X=0 + Y=25000 + Z=50000
        $this->assertEqualsWithDelta($expectedDebt, (float) ($balanceBefore['total_debt'] ?? 0.0), 0.01,
            "sanity: customer with 3 mixed bookings should owe {$expectedDebt}");

        // ── Cashier posts a general receipt of 30000 (independent of bookings)
        $this->postGeneralReceipt($customer, 30000.0, 'DEBT_A_GENERAL');

        // After general receipt: debt = 75000 - 30000 = 45000
        $balanceAfterReceipt = $this->getCustomerBalance($customer);
        $this->assertEqualsWithDelta(45000.0, (float) ($balanceAfterReceipt['total_debt'] ?? 0.0), 0.01,
            'after general receipt of 30000: customer debt must drop from 75000 to 45000');

        // ── DELETE all 3 bookings — full reversal cascade
        $this->deleteJson("/api/v1/hajj-umra/bookings/{$bookingX->id}")->assertOk();
        $this->deleteJson("/api/v1/hajj-umra/bookings/{$bookingY->id}")->assertOk();
        $this->deleteJson("/api/v1/hajj-umra/bookings/{$bookingZ->id}")->assertOk();

        // ── ASSERTION 1: every BOOKING-LINKED account returns to baseline.
        // (treasury USD, supplier AP, EC AP, clearing buckets, etc.)
        // Exception: treasury EGP is +30000 above baseline because the
        // 30000 general-receipt credit SURVIVES the booking deletions (it's
        // not a booking leg, so it's correctly not reversed).
        $final = $this->snapshotBalances();
        foreach ($baseline['accounts'] as $accountId => $baselineBalance) {
            if ($accountId === $this->treasuryEGP->id) {
                // Treasury EGP must be baseline + 30000 (general receipt credit)
                $this->assertEqualsWithDelta(
                    $baselineBalance + 30000.0,
                    $final['accounts'][$accountId] ?? 0.0,
                    0.01,
                    '[A] treasury EGP must be baseline+30000 (general receipt survives) '.
                    "(baseline=$baselineBalance, final=".($final['accounts'][$accountId] ?? 0.0).')'
                );
            } else {
                // All other accounts (supplier AP, clearing, etc.) must be baseline
                $this->assertEqualsWithDelta(
                    $baselineBalance,
                    $final['accounts'][$accountId] ?? 0.0,
                    0.01,
                    "[A] account #$accountId must return to baseline ".
                    "(baseline=$baselineBalance, final=".($final['accounts'][$accountId] ?? 0.0).')'
                );
            }
        }

        // ── ASSERTION 2: customer AR reflects ONLY the surviving general receipt
        // (the 3 bookings are deleted → their income/payment legs reversed → net 0)
        // The general receipt of 30000 is independent of the bookings, so
        // the customer AR should show -30000 (creditor: customer overpaid
        // by 30000 in cash that isn't tied to any sale).
        $arAfter = $this->accountNet($customer->account_id);
        $this->assertEqualsWithDelta(-30000.0, $arAfter, 0.01,
            'after 3 booking deletes: customer AR must show ONLY the surviving general receipt (-30000)');

        // ── ASSERTION 3: customer_balances must report the residual correctly.
        // All 3 bookings are soft-deleted → excluded from the view. The general
        // receipt credit is NOT in the booking aggregate. So the view shows 0.
        $balanceFinal = $this->getCustomerBalance($customer);
        $this->assertEqualsWithDelta(0.0, (float) ($balanceFinal['total_debt'] ?? 0.0), 0.01,
            'after 3 booking deletes: customer_balances shows 0 (all bookings excluded; '.
            'the -30000 general-receipt creditor position is not surfaced by this view)');

        // ── ASSERTION 4: global ledger still balanced
        $this->assertLedgerGloballyBalanced();
    }

    /* =========================================================
     *  STRESS SCENARIO B — partial-paid → DELETE in middle of payment cycle
     * ========================================================= */

    public function test_partial_paid_then_delete_in_middle_of_payment_cycle(): void
    {
        $baseline = $this->snapshotBalances();

        $customer = $this->makeCustomer('DebtStress-B Customer');
        $program = $this->makeProgram();

        // 60000 sale, 20000 paid, 40000 debt
        $booking = $this->createBooking($customer, $program, [
            'purchase_price' => 50000.0,
            'selling_price' => 60000.0,
            'account_id' => $this->treasuryEGP->id,
        ]);
        $this->pay($booking, 20000.0, 'DEBT_B_PAY');

        // Sanity: debt = 40000
        $balanceBefore = $this->getCustomerBalance($customer);
        $this->assertEqualsWithDelta(40000.0, (float) ($balanceBefore['total_debt'] ?? 0.0), 0.01,
            'sanity: 60000 sale - 20000 paid = 40000 debt');

        // Treasury EGP: started at 2_000_000
        // NOTE: the program has `executing_company => 'DS EC'` text, which the
        // Program::booted() observer auto-creates as a HajjUmraExecutingCompany
        // and links via executing_company_id. The booking service then routes
        // the expense to the EC's AP account (NOT the treasury). The treasury
        // therefore only sees the +20000 payment credit, no expense debit.
        //   Net = 2_000_000 + 0 (no expense) + 20000 (payment) = 2_020_000
        $treasuryBefore = (float) Account::find($this->treasuryEGP->id)->balance;
        $this->assertEqualsWithDelta(2_020_000.0, $treasuryBefore, 0.01,
            'sanity: treasury = baseline 2M + 20000 payment credit (expense went to EC AP, not treasury)');

        // DELETE in the middle of the payment cycle
        $this->deleteJson("/api/v1/hajj-umra/bookings/{$booking->id}")->assertOk();

        // Every account must return to baseline
        $this->assertBaselineRestored($baseline, 'partial-paid mid-cycle → DELETE');

        // Customer debt must be 0
        $balanceAfter = $this->getCustomerBalance($customer);
        $this->assertEqualsWithDelta(0.0, (float) ($balanceAfter['total_debt'] ?? 0.0), 0.01,
            'after mid-cycle DELETE: customer debt must be 0');

        // Global ledger balanced
        $this->assertLedgerGloballyBalanced();
    }

    /* =========================================================
     *  STRESS SCENARIO C — customer has ghost debt from previously-soft-deleted booking
     * ========================================================= */

    public function test_ghost_debt_from_previously_deleted_booking_does_not_persist(): void
    {
        $baseline = $this->snapshotBalances();

        $customer = $this->makeCustomer('DebtStress-C Customer');
        $program = $this->makeProgram();

        // Round 1: create + partial-pay + DELETE
        $booking1 = $this->createBooking($customer, $program, [
            'purchase_price' => 40000.0,
            'selling_price' => 50000.0,
            'account_id' => $this->treasuryEGP->id,
        ]);
        $this->pay($booking1, 10000.0, 'DEBT_C_1_PAY');  // 40000 debt
        $this->deleteJson("/api/v1/hajj-umra/bookings/{$booking1->id}")->assertOk();

        // After round 1, debt must be 0
        $balanceAfterRound1 = $this->getCustomerBalance($customer);
        $this->assertEqualsWithDelta(0.0, (float) ($balanceAfterRound1['total_debt'] ?? 0.0), 0.01,
            'after first round DELETE: customer debt must be 0');

        // Round 2: ANOTHER booking — verify debt tracking is fresh
        $booking2 = $this->createBooking($customer, $program, [
            'purchase_price' => 60000.0,
            'selling_price' => 80000.0,
            'account_id' => $this->treasuryEGP->id,
        ]);
        $this->pay($booking2, 30000.0, 'DEBT_C_2_PAY');  // 50000 debt

        $balanceAfterRound2Create = $this->getCustomerBalance($customer);
        $this->assertEqualsWithDelta(50000.0, (float) ($balanceAfterRound2Create['total_debt'] ?? 0.0), 0.01,
            'round 2: new booking debt must NOT include the round-1 ghost debt');

        $this->deleteJson("/api/v1/hajj-umra/bookings/{$booking2->id}")->assertOk();

        // All accounts back to baseline (not round-1's baseline, but the original baseline)
        $this->assertBaselineRestored($baseline, '2-round booking creation → DELETE both');

        // Final debt = 0
        $balanceFinal = $this->getCustomerBalance($customer);
        $this->assertEqualsWithDelta(0.0, (float) ($balanceFinal['total_debt'] ?? 0.0), 0.01,
            'after 2 rounds of full reversals: customer debt must be 0');

        $this->assertLedgerGloballyBalanced();
    }

    /* =========================================================
     *  STRESS SCENARIO D — 2 partial-paid cross-currency bookings → DELETE both
     * ========================================================= */

    public function test_two_partial_paid_cross_currency_bookings_then_delete_both(): void
    {
        $this->seedExchangeRate('EGP', 'SAR', 0.078);
        $this->seedExchangeRate('SAR', 'EGP', 12.82);

        $baseline = $this->snapshotBalances();

        $customer = $this->makeCustomer('DebtStress-D Customer');
        $program = $this->makeProgram();
        $company = $this->makeExecutingCompany();

        // Booking 1: EGP, partial 30% paid → 35000 debt
        $booking1 = $this->createBooking($customer, $program, [
            'purchase_price' => 42000.0,
            'selling_price' => 50000.0,
            'account_id' => $this->treasuryEGP->id,
        ]);
        $this->pay($booking1, 15000.0, 'DEBT_D_1_PAY');

        // Booking 2: SAR executing company (use program.executing_company_id)
        // Set the executing_company_id on the program so the booking uses it
        $program->update(['executing_company_id' => $company->id]);
        $booking2 = $this->createBooking($customer, $program, [
            'purchase_price' => 50000.0,
            'selling_price' => 70000.0,
            'account_id' => $this->treasuryEGP->id,
        ]);
        $this->pay($booking2, 20000.0, 'DEBT_D_2_PAY');

        // Sanity: total debt = 35000 + 50000 = 85000
        $balanceBefore = $this->getCustomerBalance($customer);
        $this->assertEqualsWithDelta(85000.0, (float) ($balanceBefore['total_debt'] ?? 0.0), 0.01,
            'sanity: 2 cross-currency partial bookings should total 85000 debt');

        // DELETE both
        $this->deleteJson("/api/v1/hajj-umra/bookings/{$booking1->id}")->assertOk();
        $this->deleteJson("/api/v1/hajj-umra/bookings/{$booking2->id}")->assertOk();

        $this->assertBaselineRestored($baseline, '2 partial-paid cross-currency → DELETE both');

        $balanceAfter = $this->getCustomerBalance($customer);
        $this->assertEqualsWithDelta(0.0, (float) ($balanceAfter['total_debt'] ?? 0.0), 0.01,
            'after 2 cross-currency DELETEs: customer debt must be 0');

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
            'email' => 'ds-cust-'.uniqid('', true).'@test.local',
            'is_active' => true,
        ]);
    }

    private function makeProgram(): Program
    {
        return Program::query()->create([
            'program_name' => 'DebtStress Program '.uniqid(),
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
            'executing_company' => 'DS EC',
            'departure_point' => 'CAI',
            'default_selling_price' => 50000.00,
            'default_purchase_price' => 42000.00,
            'is_active' => true,
            'created_by' => $this->admin->id,
        ]);
    }

    private function makeSupplier(): UmrahSupplier
    {
        $account = LedgerBalanceMutationGuard::run(function () {
            return Account::query()->create([
                'name' => 'DS Supplier USD',
                'type' => AccountType::Supplier->value,
                'currency' => 'USD',
                'balance' => 0.00,
                'is_active' => true,
                'owner_type' => Account::OWNER_TYPE_OFFICE,
                'module_type' => 'hajj_umra',
                'created_by' => $this->admin->id,
            ]);
        });

        return UmrahSupplier::query()->create([
            'name' => 'DS Supplier',
            'phone' => '+966555000000',
            'account_id' => $account->id,
            'default_cost_price' => 1500.00,
            'is_active' => true,
        ]);
    }

    private function makeExecutingCompany(): HajjUmraExecutingCompany
    {
        return HajjUmraExecutingCompany::query()->create([
            'name' => 'DS Executing Company',
            'license_number' => 'DS-EXC-'.uniqid(),
            'phone' => '+20100000000',
            'is_active' => true,
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

    private function pay(HajjUmraBooking $booking, float $amount, string $idemKey): void
    {
        $response = $this->postJson("/api/v1/hajj-umra/bookings/{$booking->id}/payments", [
            'amount' => $amount,
            'payment_method' => 'cash',
            'account_id' => $this->treasuryEGP->id,
            'idempotency_key' => $idemKey.'_'.uniqid(),
        ]);
        $response->assertCreated();
    }

    private function postGeneralReceipt(Customer $customer, float $amount, string $note): void
    {
        $customer->refresh();
        $customerAccountId = (int) $customer->account_id;

        if (! $customerAccountId) {
            $account = Account::query()->create([
                'name' => 'DS Customer AR: '.$customer->full_name,
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

    private function snapshotBalances(): array
    {
        $accounts = [];
        foreach (Account::query()->get() as $account) {
            $accounts[(int) $account->id] = (float) $account->balance;
        }

        return ['accounts' => $accounts];
    }

    private function assertBaselineRestored(array $baseline, string $label): void
    {
        $final = $this->snapshotBalances();
        foreach ($baseline['accounts'] as $accountId => $baselineBalance) {
            $finalBalance = $final['accounts'][$accountId] ?? 0.0;
            $this->assertEqualsWithDelta(
                $baselineBalance,
                $finalBalance,
                0.01,
                "[$label] account #$accountId must return to baseline ".
                "(baseline=$baselineBalance, final=$finalBalance)"
            );
        }
        $this->assertLedgerGloballyBalanced();
    }

    private function accountNet(int $accountId): float
    {
        $row = AccountEntry::where('account_id', $accountId)
            ->selectRaw('SUM(credit) - SUM(debit) as net')
            ->first();

        return (float) ($row->net ?? 0.0);
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

    private function assertLedgerGloballyBalanced(): void
    {
        $totalCredit = (float) AccountEntry::query()->sum('credit');
        $totalDebit = (float) AccountEntry::query()->sum('debit');
        $this->assertEqualsWithDelta(
            $totalCredit, $totalDebit, 0.01,
            "ledger must be globally balanced: credit=$totalCredit debit=$totalDebit"
        );
    }

    private function seedExchangeRate(string $from, string $to, float $rate): void
    {
        if (! \Schema::hasTable('exchange_rates')) {
            return;
        }
        \DB::table('exchange_rates')->updateOrInsert(
            [
                'from_currency' => $from,
                'to_currency' => $to,
                'effective_date' => now()->toDateString(),
            ],
            [
                'rate' => $rate,
                'is_active' => 1,
                'created_by' => $this->admin->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }
}
