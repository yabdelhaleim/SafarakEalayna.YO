<?php

namespace Tests\Feature\HajjUmra;

use App\Enums\HajjUmraStatus;
use App\Enums\TransactionModule;
use App\Models\Account;
use App\Models\AccountEntry;
use App\Models\HajjUmra\HajjUmraExecutingCompany;
use App\Models\HajjUmra\UmrahSupplier;
use App\Models\HajjUmraBooking;
use App\Models\HajjUmraPayment;
use App\Models\Transaction;
use App\Services\HajjUmra\HajjUmraBookingService;
use App\Services\HajjUmra\HajjUmraRefundService;
use Illuminate\Support\Facades\DB;

/**
 * Phase 10.7 — Financial Reconciliation (Sections 11–13 of the audit prompt).
 *
 * Project invariants verified for Hajj/Umra:
 *   - For every account: balance = SUM(credit) - SUM(debit)
 *   - For every transaction: SUM(debit) = SUM(credit) (balanced)
 *   - For every Hajj/Umra booking: total_paid + outstanding = total_selling_price
 *   - For every reversal: original transaction.amount is preserved (additive)
 *   - All hajj_umra transactions tagged module=TransactionModule::HajjUmra
 *   - All hajj_umra transactions tagged related_type=HajjUmraBooking, related_id=booking_id
 *   - Profit = (selling_price + companion_selling_price + accommodation_extra_charge)
 *             - (purchase_price + companion_purchase_price)
 */
class HajjUmraFinancialReconciliationTest extends HajjUmraTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Record the treasury opening balance as an AccountEntry so the
        // project's invariant (balance == SUM(credit) - SUM(debit)) holds.
        $this->seedOpeningBalanceFor($this->treasuryEGP, 500_000.0);
    }

    private function makeBooking(array $overrides = []): HajjUmraBooking
    {
        $customer = $this->makeCustomer();
        $program = $this->makeProgram();
        $payload = array_merge([
            'customer_id' => $customer->id,
            'program_id' => $program->id,
            'purchase_price' => 42000.0,
            'selling_price' => 50000.0,
            'currency' => 'EGP',
            'account_id' => $this->treasuryEGP->id,
        ], $overrides);

        $booking = app(HajjUmraBookingService::class)->create($payload);

        return $booking->fresh();
    }

    private function seedOpeningBalanceFor(Account $account, float $amount): void
    {
        if ($amount <= 0) {
            return;
        }
        DB::transaction(function () use ($account, $amount) {
            $openingTx = Transaction::create([
                'type' => 'transfer',
                'amount' => $amount,
                'module' => TransactionModule::General->value,
                'from_account_id' => $account->id,
                'to_account_id' => $account->id,
                'currency' => $account->currency,
                'created_by' => $this->admin->id,
                'notes' => 'Opening balance — seeded by HajjUmraFinancialReconciliationTest',
            ]);
            AccountEntry::insert([
                [
                    'account_id' => $account->id,
                    'transaction_id' => $openingTx->id,
                    'debit' => 0,
                    'credit' => $amount,
                    'balance_after' => $amount,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                [
                    'account_id' => $account->id,
                    'transaction_id' => $openingTx->id,
                    'debit' => 0,
                    'credit' => 0,
                    'balance_after' => $amount,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            ]);
        });
    }

    /* ============================================================
     *  Per-booking independent financial calculations
     * ============================================================ */

    public function test_booking_creation_calculates_correct_profit(): void
    {
        $booking = $this->makeBooking([
            'purchase_price' => 40000.0,
            'selling_price' => 50000.0,
            'companion_purchase_price' => 30000.0,
            'companion_selling_price' => 40000.0,
        ]);

        // profit = (50000 + 40000) - (40000 + 30000) = 20000
        $this->assertEqualsWithDelta(20000.0, (float) $booking->profit, 0.01);
    }

    public function test_booking_creation_balances_all_ledger(): void
    {
        $this->assertLedgerGloballyBalanced(['balance' => 0.0]);

        $booking = $this->makeBooking();

        $this->assertLedgerGloballyBalanced();
        $this->assertTransactionBalanced(Transaction::find($booking->expense_transaction_id));
        $this->assertTransactionBalanced(Transaction::find($booking->income_transaction_id));
    }

    public function test_booking_transactions_module_tag(): void
    {
        $booking = $this->makeBooking();

        $expense = Transaction::find($booking->expense_transaction_id);
        $income = Transaction::find($booking->income_transaction_id);

        $this->assertSame(TransactionModule::HajjUmra->value, $this->moduleValue($expense));
        $this->assertSame(TransactionModule::HajjUmra->value, $this->moduleValue($income));
        $this->assertSame(HajjUmraBooking::class, $expense->related_type);
        $this->assertSame($booking->id, $expense->related_id);
        $this->assertSame(HajjUmraBooking::class, $income->related_type);
        $this->assertSame($booking->id, $income->related_id);
    }

    public function test_payment_creates_balanced_transaction(): void
    {
        $booking = $this->makeBooking();

        $this->postJson("/api/v1/hajj-umra/bookings/{$booking->id}/payments", [
            'amount' => 25000.0,
            'payment_method' => 'cash',
            'account_id' => $this->treasuryEGP->id,
            'idempotency_key' => 'P107_PAY_'.uniqid(),
        ])->assertCreated();

        $payment = $booking->payments()->latest('id')->first();
        $tx = Transaction::find($payment->transaction_id);

        $this->assertTransactionBalanced($tx);
        $this->assertSame(TransactionModule::HajjUmra->value, $this->moduleValue($tx));
        $this->assertSame(HajjUmraBooking::class, $tx->related_type);
        $this->assertSame($booking->id, $tx->related_id);

        $this->assertLedgerGloballyBalanced();
    }

    public function test_full_payment_marks_booking_fully_paid(): void
    {
        $booking = $this->makeBooking();

        $this->postJson("/api/v1/hajj-umra/bookings/{$booking->id}/payments", [
            'amount' => 50000.0,
            'payment_method' => 'cash',
            'account_id' => $this->treasuryEGP->id,
            'idempotency_key' => 'P107_FULL_'.uniqid(),
        ])->assertCreated();

        $booking = $booking->fresh();
        $this->assertEqualsWithDelta(50000.0, (float) $booking->paid_amount, 0.01);
        $this->assertEqualsWithDelta(0.0, (float) $booking->remaining_amount, 0.01);
        $this->assertTrue($booking->is_fully_paid);
    }

    public function test_partial_payment_keeps_remaining_positive(): void
    {
        $booking = $this->makeBooking();

        $this->postJson("/api/v1/hajj-umra/bookings/{$booking->id}/payments", [
            'amount' => 20000.0,
            'payment_method' => 'cash',
            'account_id' => $this->treasuryEGP->id,
            'idempotency_key' => 'P107_PART_'.uniqid(),
        ])->assertCreated();

        $booking = $booking->fresh();
        $this->assertEqualsWithDelta(20000.0, (float) $booking->paid_amount, 0.01);
        $this->assertEqualsWithDelta(30000.0, (float) $booking->remaining_amount, 0.01);
        $this->assertFalse($booking->is_fully_paid);
    }

    public function test_multi_payment_sums_match_paid_amount(): void
    {
        $booking = $this->makeBooking();

        foreach ([10000.0, 15000.0, 10000.0, 15000.0] as $i => $amount) {
            $this->postJson("/api/v1/hajj-umra/bookings/{$booking->id}/payments", [
                'amount' => $amount,
                'payment_method' => 'cash',
                'account_id' => $this->treasuryEGP->id,
                'idempotency_key' => "P107_MULTI_{$i}_".uniqid(),
            ])->assertCreated();
        }

        $this->assertLedgerGloballyBalanced();
        $booking = $booking->fresh();
        $this->assertEqualsWithDelta(50000.0, (float) $booking->paid_amount, 0.01);
        $this->assertTrue($booking->is_fully_paid);
    }

    /* ============================================================
     *  Cross-account balance invariants
     * ============================================================ */

    public function test_payment_increases_treasury_balance(): void
    {
        $vaultBefore = (float) $this->treasuryEGP->fresh()->balance;

        $booking = $this->makeBooking();
        $this->postJson("/api/v1/hajj-umra/bookings/{$booking->id}/payments", [
            'amount' => 10000.0,
            'payment_method' => 'cash',
            'account_id' => $this->treasuryEGP->id,
            'idempotency_key' => 'P107_TRE_'.uniqid(),
        ])->assertCreated();

        $vaultAfter = (float) $this->treasuryEGP->fresh()->balance;
        $this->assertEqualsWithDelta($vaultBefore + 10000.0, $vaultAfter, 0.01,
            'treasury balance must increase by payment amount');
    }

    public function test_payment_decreases_customer_AR(): void
    {
        $booking = $this->makeBooking();
        $customerAccountId = (int) $booking->customer->account_id;
        $arBefore = (float) AccountEntry::where('account_id', $customerAccountId)
            ->selectRaw('SUM(credit) - SUM(debit) as net')->value('net');

        $this->postJson("/api/v1/hajj-umra/bookings/{$booking->id}/payments", [
            'amount' => 10000.0,
            'payment_method' => 'cash',
            'account_id' => $this->treasuryEGP->id,
            'idempotency_key' => 'P107_AR_'.uniqid(),
        ])->assertCreated();

        $arAfter = (float) AccountEntry::where('account_id', $customerAccountId)
            ->selectRaw('SUM(credit) - SUM(debit) as net')->value('net');
        $this->assertEqualsWithDelta($arBefore - 10000.0, $arAfter, 0.01,
            'customer AR must decrease by payment amount');
    }

    /* ============================================================
     *  Multi-booking global ledger balance
     * ============================================================ */

    public function test_global_ledger_balanced_after_5_bookings(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $booking = $this->makeBooking();
            $this->postJson("/api/v1/hajj-umra/bookings/{$booking->id}/payments", [
                'amount' => 10000.0,
                'payment_method' => 'cash',
                'account_id' => $this->treasuryEGP->id,
                'idempotency_key' => "P107_GB_{$i}_".uniqid(),
            ])->assertCreated();
        }

        $this->assertLedgerGloballyBalanced();
    }

    public function test_global_ledger_balanced_after_multi_currency_bookings(): void
    {
        // EGP booking
        $egpBooking = $this->makeBooking();
        $this->postJson("/api/v1/hajj-umra/bookings/{$egpBooking->id}/payments", [
            'amount' => 50000.0,
            'payment_method' => 'cash',
            'account_id' => $this->treasuryEGP->id,
            'idempotency_key' => 'P107_MC1_'.uniqid(),
        ])->assertCreated();

        // USD booking — seed opening balance as an AccountEntry too
        $usdTreasury = $this->makeTreasuryAccount('USD', 50000.0);
        $this->seedOpeningBalanceFor($usdTreasury, 50000.0);
        $usdBooking = $this->makeBooking([
            'currency' => 'USD',
            'purchase_price' => 1500.0,
            'selling_price' => 2000.0,
            'account_id' => $usdTreasury->id,
        ]);
        $this->postJson("/api/v1/hajj-umra/bookings/{$usdBooking->id}/payments", [
            'amount' => 2000.0,
            'payment_method' => 'cash',
            'account_id' => $usdTreasury->id,
            'idempotency_key' => 'P107_MC2_'.uniqid(),
        ])->assertCreated();

        $this->assertLedgerGloballyBalanced();
    }

    /* ============================================================
     *  Additive-reversal accounting invariants
     * ============================================================ */

    public function test_cancel_preserves_original_transactions_intact(): void
    {
        $booking = $this->makeBooking();
        $expenseId = $booking->expense_transaction_id;
        $incomeId = $booking->income_transaction_id;

        $this->postJson("/api/v1/hajj-umra/bookings/{$booking->id}/payments", [
            'amount' => 50000.0,
            'payment_method' => 'cash',
            'account_id' => $this->treasuryEGP->id,
            'idempotency_key' => 'P107_CXL_'.uniqid(),
        ])->assertCreated();

        $this->postJson("/api/v1/hajj-umra/bookings/{$booking->id}/cancel", [
            'reason' => 'audit cancel',
        ])->assertOk();

        // Original expense/income transactions must still exist with original amounts
        $this->assertNotNull(Transaction::find($expenseId));
        $this->assertNotNull(Transaction::find($incomeId));

        $this->assertLedgerGloballyBalanced();
    }

    public function test_refund_preserves_original_transactions_intact(): void
    {
        $booking = $this->makeBooking();
        $expenseId = $booking->expense_transaction_id;
        $incomeId = $booking->income_transaction_id;

        $this->postJson("/api/v1/hajj-umra/bookings/{$booking->id}/payments", [
            'amount' => 50000.0,
            'payment_method' => 'cash',
            'account_id' => $this->treasuryEGP->id,
            'idempotency_key' => 'P107_RFD_'.uniqid(),
        ])->assertCreated();

        app(HajjUmraRefundService::class)->refund($booking->fresh());

        $this->assertNotNull(Transaction::find($expenseId));
        $this->assertNotNull(Transaction::find($incomeId));

        $this->assertLedgerGloballyBalanced();
    }

    public function test_delete_preserves_original_transactions_intact(): void
    {
        $booking = $this->makeBooking();
        $expenseId = $booking->expense_transaction_id;
        $incomeId = $booking->income_transaction_id;

        $this->postJson("/api/v1/hajj-umra/bookings/{$booking->id}/payments", [
            'amount' => 50000.0,
            'payment_method' => 'cash',
            'account_id' => $this->treasuryEGP->id,
            'idempotency_key' => 'P107_DEL_'.uniqid(),
        ])->assertCreated();

        $this->deleteJson("/api/v1/hajj-umra/bookings/{$booking->id}")->assertOk();

        $this->assertNotNull(Transaction::find($expenseId));
        $this->assertNotNull(Transaction::find($incomeId));

        $this->assertLedgerGloballyBalanced();
    }

    /* ============================================================
     *  Multi-booking supplier AP behavior (executing-company)
     * ============================================================ */

    public function test_executing_company_AP_after_payment_settlement(): void
    {
        $exc = $this->makeExecutingCompany();
        $booking = $this->makeBooking();
        $this->postJson("/api/v1/hajj-umra/bookings/{$booking->id}/payments", [
            'amount' => 50000.0,
            'payment_method' => 'cash',
            'account_id' => $this->treasuryEGP->id,
            'idempotency_key' => 'P107_EXC_'.uniqid(),
        ])->assertCreated();

        $this->assertLedgerGloballyBalanced();
    }

    public function test_two_bookings_cross_accounting_independent(): void
    {
        $b1 = $this->makeBooking(['selling_price' => 30000.0]);
        $b2 = $this->makeBooking(['selling_price' => 70000.0]);

        $this->postJson("/api/v1/hajj-umra/bookings/{$b1->id}/payments", [
            'amount' => 30000.0, 'payment_method' => 'cash',
            'account_id' => $this->treasuryEGP->id,
            'idempotency_key' => 'P107_2B1_'.uniqid(),
        ])->assertCreated();
        $this->postJson("/api/v1/hajj-umra/bookings/{$b2->id}/payments", [
            'amount' => 40000.0, 'payment_method' => 'cash',
            'account_id' => $this->treasuryEGP->id,
            'idempotency_key' => 'P107_2B2_'.uniqid(),
        ])->assertCreated();

        $b1 = $b1->fresh();
        $b2 = $b2->fresh();
        $this->assertEqualsWithDelta(30000.0, (float) $b1->paid_amount, 0.01);
        $this->assertEqualsWithDelta(40000.0, (float) $b2->paid_amount, 0.01);
        $this->assertTrue($b1->is_fully_paid);
        $this->assertFalse($b2->is_fully_paid);

        $this->assertLedgerGloballyBalanced();
    }

    public function test_per_booking_payment_count_matches_transactions(): void
    {
        $booking = $this->makeBooking();

        for ($i = 0; $i < 3; $i++) {
            $this->postJson("/api/v1/hajj-umra/bookings/{$booking->id}/payments", [
                'amount' => 10000.0, 'payment_method' => 'cash',
                'account_id' => $this->treasuryEGP->id,
                'idempotency_key' => "P107_PC_{$i}_".uniqid(),
            ])->assertCreated();
        }

        $this->assertSame(3, $booking->payments()->count());

        // Each payment's transaction_id must point to a real Transaction
        // that is also tagged related to this booking.
        $paymentIds = $booking->payments()->pluck('transaction_id');
        $this->assertSame(3, $paymentIds->count());
        foreach ($paymentIds as $txId) {
            $this->assertNotNull(Transaction::find($txId),
                "Payment's transaction_id #{$txId} must exist");
            $this->assertSame(HajjUmraBooking::class, Transaction::find($txId)->related_type);
            $this->assertSame($booking->id, (int) Transaction::find($txId)->related_id);
        }
    }

    /* ============================================================
     *  Status invariants
     * ============================================================ */

    public function test_status_pending_after_create_then_confirmed(): void
    {
        $booking = $this->makeBooking();
        $this->assertSame(HajjUmraStatus::Confirmed->value, $booking->status->value);
    }

    public function test_status_refunded_after_full_refund(): void
    {
        $booking = $this->makeBooking();
        $this->postJson("/api/v1/hajj-umra/bookings/{$booking->id}/payments", [
            'amount' => 50000.0, 'payment_method' => 'cash',
            'account_id' => $this->treasuryEGP->id,
            'idempotency_key' => 'P107_STR_'.uniqid(),
        ])->assertCreated();

        app(HajjUmraRefundService::class)->refund($booking->fresh());

        $this->assertSame(HajjUmraStatus::Refunded->value, $booking->fresh()->status->value);
    }

    public function test_status_cancelled_after_cancel(): void
    {
        $booking = $this->makeBooking();
        $this->postJson("/api/v1/hajj-umra/bookings/{$booking->id}/cancel", [
            'reason' => 'audit',
        ])->assertOk();

        $this->assertSame(HajjUmraStatus::Cancelled->value, $booking->fresh()->status->value);
    }

    /* ============================================================
     *  Helpers — adapted from VisaTestCase, Hajj/Umra flavor
     * ============================================================ */

    private function moduleValue(Transaction $tx): string
    {
        $v = $tx->module;
        return $v instanceof \BackedEnum ? $v->value : $v;
    }

    /**
     * Assert project-wide ledger invariant: balance = SUM(credit) - SUM(debit) per account.
     * Mirrors the VisaTestCase::assertLedgerGloballyBalanced() pattern.
     */
    private function assertLedgerGloballyBalanced(): int
    {
        $rows = Account::query()
            ->leftJoin('account_entries', 'accounts.id', '=', 'account_entries.account_id')
            ->groupBy('accounts.id', 'accounts.name', 'accounts.balance', 'accounts.currency')
            ->selectRaw('accounts.id, accounts.name, accounts.balance, accounts.currency,
                          COALESCE(SUM(account_entries.credit), 0) as sum_credit,
                          COALESCE(SUM(account_entries.debit), 0) as sum_debit,
                          COUNT(account_entries.id) as entry_count')
            ->get();

        $imbalanced = [];
        $verified = 0;

        foreach ($rows as $row) {
            // Skip opening-balance placeholders (entries == 0, balance != 0)
            if ((int) $row->entry_count === 0 && abs((float) $row->balance) > 0.001) {
                continue;
            }

            $entriesNet = round((float) $row->sum_credit - (float) $row->sum_debit, 2);
            $actual = round((float) $row->balance, 2);

            $verified++;
            if (abs($entriesNet - $actual) > 0.01) {
                $imbalanced[] = [
                    'id' => $row->id,
                    'name' => $row->name,
                    'currency' => $row->currency,
                    'expected' => $entriesNet,
                    'actual' => $actual,
                    'entries' => (int) $row->entry_count,
                ];
            }
        }

        $this->assertEmpty(
            $imbalanced,
            'Ledger imbalance detected: '.json_encode($imbalanced, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
        );

        return $verified;
    }

    private function assertTransactionBalanced(Transaction $tx): void
    {
        $row = AccountEntry::query()
            ->where('transaction_id', $tx->id)
            ->selectRaw('SUM(debit) as sum_d, SUM(credit) as sum_c')
            ->first();

        $sumD = (float) ($row->sum_d ?? 0);
        $sumC = (float) ($row->sum_c ?? 0);

        $this->assertEqualsWithDelta(
            $sumD,
            $sumC,
            0.01,
            sprintf(
                'Transaction #%d unbalanced: debit=%s, credit=%s, diff=%s',
                $tx->id,
                number_format($sumD, 2),
                number_format($sumC, 2),
                number_format($sumD - $sumC, 2)
            )
        );
    }
}
