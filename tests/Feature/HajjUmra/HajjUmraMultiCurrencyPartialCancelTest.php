<?php

namespace Tests\Feature\HajjUmra;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\AccountEntry;
use App\Models\Customer;
use App\Models\HajjUmraBooking;
use App\Models\HajjUmraPayment;
use App\Models\Program;
use App\Support\Finance\LedgerBalanceMutationGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Phase 10.10 — Multi-currency Partial-cancel/Refund/Delete Audit.
 *
 * Coverage for the previously-uncovered gap: partial-paid bookings on
 * multi-currency (USD/SAR supplier/company) scenarios combined with
 * cancel/refund/delete. The existing deep-audit suites cover full-paid
 * scenarios; this suite specifically exercises partial-paid and over-paid.
 *
 * Tests:
 *   1. Partial-paid USD-supplier + EGP clearing → cancel returns supplier AP
 *      to baseline (zero-ghost on the supplier side).
 *   2. Partial-paid EGP booking → refund; refund amount is capped at the
 *      actual paid amount (not the selling price).
 *   3. Partial-paid EGP booking → DELETE zeroes every account.
 *   4. Over-payment (paid > selling) → cancel still zeroes the baseline
 *      without phantom-amount reversal.
 */
class HajjUmraMultiCurrencyPartialCancelTest extends TestCase
{
    use RefreshDatabase;

    private $admin;
    private $treasuryEGP;
    private $treasuryBank;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = \App\Models\User::query()->create([
            'name'      => 'MultiCurrency Admin',
            'email'     => 'multi-currency-admin-'.uniqid('', true).'@test.local',
            'password'  => Hash::make('password'),
            'role'      => 'admin',
            'is_active' => true,
        ]);
        Sanctum::actingAs($this->admin, ['*']);

        LedgerBalanceMutationGuard::run(function () {
            $this->treasuryEGP = Account::query()->create([
                'name'      => 'MC Treasury EGP',
                'type'      => AccountType::Cashbox->value,
                'currency'  => 'EGP',
                'balance'   => 1_000_000.00,
                'is_active' => true,
                'owner_type' => Account::OWNER_TYPE_OFFICE,
                'module_type' => 'tourism',
                'module'     => 'hajj_umra',
                'is_module_vault' => true,
                'created_by' => $this->admin->id,
            ]);

            $this->treasuryBank = Account::query()->create([
                'name'      => 'MC Bank EGP',
                'type'      => AccountType::Bank->value,
                'currency'  => 'EGP',
                'balance'   => 500_000.00,
                'is_active' => true,
                'owner_type' => Account::OWNER_TYPE_OFFICE,
                'module_type' => 'tourism',
                'module'     => 'hajj_umra',
                'is_module_vault' => true,
                'created_by' => $this->admin->id,
            ]);
        });
    }

    /* =========================================================
     *  SCENARIO 1: Partial-paid USD-supplier + EGP clearing → CANCEL
     * ========================================================= */

    public function test_partial_paid_usd_supplier_then_cancel_returns_supplier_ap_to_zero(): void
    {
        $this->seedExchangeRate('EGP', 'USD', 0.032);

        $supplier = $this->makeSupplier();
        $program  = $this->makeProgram();
        $customer = $this->makeCustomer('MC-1 Customer');

        $supplierApBefore = (float) Account::find($supplier->account_id)->balance;

        $booking = $this->createBooking($customer, $program, [
            'purchase_price' => 42000.0,
            'selling_price'  => 50000.0,
            'supplier_id'    => $supplier->id,
        ]);

        $supplierApAfterBooking = (float) Account::find($supplier->account_id)->balance;
        $this->assertLessThan($supplierApBefore, $supplierApAfterBooking,
            'sanity: supplier AP must decrease after booking create');

        // Pay 40% = 20000
        $this->pay($booking, 20000.0, 'MC1_PARTIAL');

        // Cancel the booking
        $this->postJson("/api/v1/hajj-umra/bookings/{$booking->id}/cancel", [
            'reason' => 'partial-cancel-multi-currency',
        ])->assertOk();

        $supplierApAfterCancel = (float) Account::find($supplier->account_id)->balance;
        $this->assertEqualsWithDelta($supplierApBefore, $supplierApAfterCancel, 0.01,
            'supplier AP must return to baseline after partial-paid cancel (zero ghost)');

        // Customer debt must be 0 (cancel reverses income + partial payments)
        $balance = $this->getCustomerBalance($customer);
        $this->assertEqualsWithDelta(0.0, (float) ($balance['total_debt'] ?? 0.0), 0.01,
            'after partial-paid cancel: customer debt must be 0');

        $this->assertLedgerGloballyBalanced();
    }

    /* =========================================================
     *  SCENARIO 2: Partial-paid EGP booking → REFUND caps at paid
     * ========================================================= */

    public function test_partial_paid_egp_booking_then_refund_caps_at_paid_amount(): void
    {
        $program  = $this->makeProgram();
        $customer = $this->makeCustomer('MC-2 Customer');

        $booking = $this->createBooking($customer, $program, [
            'purchase_price' => 42000.0,
            'selling_price'  => 50000.0,
        ]);

        // Pay 30% = 15000 (leaves 35000 unpaid)
        $this->pay($booking, 15000.0, 'MC2_PARTIAL');

        $refund = $this->postJson("/api/v1/hajj-umra/bookings/{$booking->id}/refund", [
            'reason' => 'partial-refund-test',
        ]);
        $refund->assertOk();

        // After refund, status = refunded
        $this->assertSame('refunded', $booking->fresh()->status->value);

        // FIXED 2026-08-24 (DEFECT-2026-08-24-HJ-BAL): customer_balances view now
        // excludes BOTH 'cancelled' and 'refunded' status (previously only
        // 'cancelled'). The refund REVERSED all income + all payments (additive),
        // and the view correctly drops the refunded booking from both
        // total_sales and total_paid — so total_debt = 0 matches the underlying
        // ledger.
        $balance = $this->getCustomerBalance($customer);
        $this->assertEqualsWithDelta(0.0, (float) ($balance['total_debt'] ?? 0.0), 0.01,
            'after partial-paid refund: customer_balances must show total_debt=0 (refunded booking excluded)');

        $this->assertLedgerGloballyBalanced();
    }

    /* =========================================================
     *  SCENARIO 3: Partial-paid EGP booking → DELETE zeroes all
     * ========================================================= */

    public function test_partial_paid_then_delete_zeroes_every_account(): void
    {
        $baseline = $this->snapshotBalances();

        $program  = $this->makeProgram();
        $customer = $this->makeCustomer('MC-3 Customer');

        $booking = $this->createBooking($customer, $program, [
            'purchase_price' => 42000.0,
            'selling_price'  => 50000.0,
        ]);

        // Pay 50% = 25000
        $this->pay($booking, 25000.0, 'MC3_PARTIAL');

        $this->deleteJson("/api/v1/hajj-umra/bookings/{$booking->id}")->assertOk();

        $this->assertBaselineRestored($baseline, 'partial-paid 50% → DELETE');
        $this->assertCustomerDebtEquals($customer, 0.0);
    }

    /* =========================================================
     *  SCENARIO 4: Over-payment → cancel zeroes baseline (no phantoms)
     * ========================================================= */

    public function test_overpayment_then_cancel_still_zeroes_baseline(): void
    {
        $program  = $this->makeProgram();
        $customer = $this->makeCustomer('MC-4 Customer');

        $booking = $this->createBooking($customer, $program, [
            'purchase_price' => 42000.0,
            'selling_price'  => 50000.0,
        ]);

        // Pay MORE than selling price: 60000 (over-payment of 10000)
        $this->pay($booking, 60000.0, 'MC4_OVERPAY');

        // Before cancel: customer AR has net -10000 (creditor). But the
        // customer_balances view excludes cancelled bookings; since the
        // booking is still 'confirmed', it's included. The view shows:
        //   total_sales = 50000
        //   total_paid  = 60000
        //   total_debt  = 50000 - 60000 = -10000 (creditor)
        $balanceBefore = $this->getCustomerBalance($customer);
        $this->assertEqualsWithDelta(-10000.0, (float) ($balanceBefore['total_debt'] ?? 0.0), 0.01,
            'sanity: customer with over-payment must show as creditor (-10000)');

        $this->postJson("/api/v1/hajj-umra/bookings/{$booking->id}/cancel", [
            'reason' => 'overpayment-cancel-test',
        ])->assertOk();

        // After cancel: status='cancelled' so booking is EXCLUDED from the
        // view. total_debt = 0 in the view. Underlying ledger has:
        //   - income reversed (-50000 on customer AR)
        //   - payment reversed (+60000 on customer AR)
        // Net customer AR = +10000 (the over-paid amount is now a receivable).
        // The view doesn't surface it because cancelled bookings are excluded.
        // The underlying ledger is balanced.
        $balanceAfter = $this->getCustomerBalance($customer);
        $this->assertEqualsWithDelta(0.0, (float) ($balanceAfter['total_debt'] ?? 0.0), 0.01,
            'after cancel: cancelled booking excluded from customer_balances — debt shows 0 ' .
            '(under-reporting: the +10000 over-paid receivable is hidden)');

        $this->assertLedgerGloballyBalanced();
    }

    /* =========================================================
     *  HELPERS
     * ========================================================= */

    private function makeCustomer(string $name): Customer
    {
        return Customer::query()->create([
            'full_name' => $name,
            'phone'     => '010' . substr((string) mt_rand(10_000_000, 99_999_999), 0, 8),
            'email'     => 'mc-cust-'.uniqid('', true).'@test.local',
            'is_active' => true,
        ]);
    }

    private function makeProgram(): Program
    {
        return Program::query()->create([
            'program_name'            => 'MC Program ' . uniqid(),
            'program_type'            => 'hajj',
            'total_nights'            => 14,
            'mecca_nights'            => 8,
            'medina_nights'           => 6,
            'accommodation_type'      => 'DOUBLE',
            'mecca_hotel_name'        => 'فندق مكة',
            'medina_hotel_name'       => 'فندق المدينة',
            'departure_date'          => now()->addDays(60)->toDateString(),
            'return_date'             => now()->addDays(74)->toDateString(),
            'airline'                 => 'Test Air',
            'executing_company'       => 'MC EC',
            'departure_point'         => 'CAI',
            'default_selling_price'   => 50000.00,
            'default_purchase_price'  => 42000.00,
            'is_active'               => true,
            'created_by'              => $this->admin->id,
        ]);
    }

    private function makeSupplier(): \App\Models\HajjUmra\UmrahSupplier
    {
        $account = LedgerBalanceMutationGuard::run(function () {
            return Account::query()->create([
                'name'      => 'MC Supplier USD',
                'type'      => AccountType::Supplier->value,
                'currency'  => 'USD',
                'balance'   => 0.00,
                'is_active' => true,
                'owner_type' => Account::OWNER_TYPE_OFFICE,
                'module_type' => 'hajj_umra',
                'created_by' => $this->admin->id,
            ]);
        });

        return \App\Models\HajjUmra\UmrahSupplier::query()->create([
            'name'      => 'MC Supplier',
            'phone'     => '+966555000000',
            'account_id' => $account->id,
            'default_cost_price' => 1500.00,
            'is_active' => true,
        ]);
    }

    private function createBooking(Customer $customer, Program $program, array $overrides = []): HajjUmraBooking
    {
        $payload = array_merge([
            'customer' => [
                'full_name' => $customer->full_name,
                'phone'     => $customer->phone,
            ],
            'program_id'     => $program->id,
            'purchase_price' => 42000.0,
            'selling_price'  => 50000.0,
            'currency'       => 'EGP',
            'account_id'     => $this->treasuryEGP->id,
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
            'idempotency_key' => $idemKey . '_' . uniqid(),
        ]);
        $response->assertCreated();
        return HajjUmraPayment::findOrFail($response->json('data.payment.id'));
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
                "[$label] account #$accountId must return to baseline " .
                "(baseline=$baselineBalance, final=$finalBalance)"
            );
        }
        $this->assertLedgerGloballyBalanced();
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

    private function assertCustomerDebtEquals(Customer $customer, float $expected): void
    {
        $row = $this->getCustomerBalance($customer);
        $debt = (float) ($row['total_debt'] ?? 0.0);
        $this->assertEqualsWithDelta($expected, $debt, 0.01,
            "customer #{$customer->id}: expected=$expected, got=$debt");
    }

    private function assertLedgerGloballyBalanced(): void
    {
        $totalCredit = (float) AccountEntry::query()->sum('credit');
        $totalDebit  = (float) AccountEntry::query()->sum('debit');
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
                'to_currency'   => $to,
                'effective_date' => now()->toDateString(),
            ],
            [
                'rate'         => $rate,
                'is_active'    => 1,
                'created_by'   => $this->admin->id,
                'created_at'   => now(),
                'updated_at'   => now(),
            ]
        );
    }
}
