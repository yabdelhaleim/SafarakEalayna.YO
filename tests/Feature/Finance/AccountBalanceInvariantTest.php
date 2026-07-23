<?php

namespace Tests\Feature\Finance;

use App\Enums\AccountType;
use App\Enums\TransactionModule;
use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\AccountEntry;
use App\Models\Customer;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Finance\AccountService;
use App\Services\Finance\TransactionService;
use App\Support\Finance\LedgerBalanceMutationGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * ─────────────────────────────────────────────────────────────────────
 * AccountBalanceInvariantTest
 *
 * Regression test for the bug found in 2026-07-22 where
 * `TransactionService::recordTransfer()` and
 * `recordJournalTransfer()` wrote ledger entries in the WRONG
 * direction (source got CREDIT instead of DEBIT).
 *
 * The tests below pin the **project's invariant** in place so the bug
 * cannot re-appear:
 *
 *   balance = SUM(credit) - SUM(debit) on `account_entries` for the
 *   account.
 *
 * Each scenario performs an operation then asserts both
 *   (a) the per-transaction `Σdebit == Σcredit` (double-entry balance)
 *   (b) the per-account `balance == SUM(credit) - SUM(debit)` (running
 *       balance drift == 0)
 *
 * If either fails, the test asserts the **specific** direction mistake
 * (e.g. "source account has CREDIT — should be DEBIT") so the next
 * developer immediately knows what regressed.
 * ─────────────────────────────────────────────────────────────────────
 */
class AccountBalanceInvariantTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    /** @var array<string, Account> */
    protected array $accounts = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::query()->create([
            'name' => 'Invariant Tester',
            'email' => 'invariant-tester@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);
        $this->actingAs($this->user);

        $this->seedAccounts();
    }

    /**
     * Build a minimal liquidity graph for the tests:
     *   bank-office-egp:  +30,000  (the office's main bank account)
     *   cashbox-office-1:  +5,000  (branch one)
     *   cashbox-office-2:  +2,000  (branch two)
     */
    protected function seedAccounts(): void
    {
        $bank = Account::create([
            'name' => 'Test Bank EGP',
            'type' => AccountType::Bank,
            'currency' => 'EGP',
            'balance' => 0,
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'office',
            'is_module_vault' => true,
            'created_by' => $this->user->id,
        ]);
        // Opening credit 30,000
        $this->openWith($bank, 30000);

        $cash1 = Account::create([
            'name' => 'Test Cashbox Branch 1',
            'type' => AccountType::Cashbox,
            'currency' => 'EGP',
            'balance' => 0,
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'office',
            'is_module_vault' => false,
            'created_by' => $this->user->id,
        ]);
        $this->openWith($cash1, 5000);

        $cash2 = Account::create([
            'name' => 'Test Cashbox Branch 2',
            'type' => AccountType::Cashbox,
            'currency' => 'EGP',
            'balance' => 0,
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'office',
            'is_module_vault' => false,
            'created_by' => $this->user->id,
        ]);
        $this->openWith($cash2, 2000);

        $this->accounts = [
            'bank'  => $bank,
            'cash1' => $cash1,
            'cash2' => $cash2,
        ];
    }

    /**
     * Update Account::balance through LedgerBalanceMutationGuard and
     * record a single opening credit entry. This mirrors what
     * AccountService::createAccount does in production.
     */
    protected function openWith(Account $a, float $amount): void
    {
        $a->balance = $amount;
        LedgerBalanceMutationGuard::run(fn () => $a->save());

        AccountEntry::create([
            'account_id' => $a->id,
            'transaction_id' => null,
            'debit' => 0.00,
            'credit' => $amount,
            'balance_after' => $amount,
            'notes' => 'رصيد افتتاحي',
        ]);
    }

    /**
     * Compute `SUM(credit) - SUM(debit)` for an account.
     */
    protected function entryNet(int $accountId): float
    {
        $row = DB::table('account_entries')
            ->where('account_id', $accountId)
            ->selectRaw('COALESCE(SUM(credit),0) - COALESCE(SUM(debit),0) AS net')
            ->first();

        return (float) ($row->net ?? 0);
    }

    /**
     * Assert the project's invariant:
     *   `balance == SUM(credit) - SUM(debit)`
     */
    protected function assertInvariant(Account $account): void
    {
        $expected = $this->entryNet($account->id);
        $actual   = (float) $account->fresh()->balance;

        self::assertEqualsWithDelta(
            $expected,
            $actual,
            0.011,
            "Invariant violation on account #{$account->id} ({$account->name}): "
            . "balance={$actual}, SUM(credit-debit)={$expected}, diff=" . ($actual - $expected)
        );
    }

    /**
     * ─────────────────────────────────────────────────────────────
     * ASSERT 1: Opening entries satisfy the invariant for every
     *           account.
     * ─────────────────────────────────────────────────────────────
     */
    public function test_opening_entries_satisfy_invariant(): void
    {
        foreach ($this->accounts as $key => $account) {
            $this->assertInvariant($account);
        }

        // Also explicit numeric assertions for clarity
        self::assertSame(30000.0, (float) $this->accounts['bank']->fresh()->balance);
        self::assertSame(5000.0, (float) $this->accounts['cash1']->fresh()->balance);
        self::assertSame(2000.0, (float) $this->accounts['cash2']->fresh()->balance);
    }

    /**
     * ─────────────────────────────────────────────────────────────
     * ASSERT 2 (THE MAIN ONE): `recordTransfer()` writes entries in
     *           the project's direction (source=DEBIT, dest=CREDIT)
     *           AND respects the balance invariant.
     *
     * This is the regression test for the 2026-07-22 bug.
     * ─────────────────────────────────────────────────────────────
     */
    public function test_record_transfer_writes_entries_in_correct_direction(): void
    {
        $transferService = app(TransactionService::class);

        $transfer = $transferService->recordTransfer([
            'from_account_id' => $this->accounts['bank']->id,
            'to_account_id'   => $this->accounts['cash1']->id,
            'amount'          => 5000.0,
            'currency'        => 'EGP',
            'module'          => 'office',
            'notes'           => 'regression-invariant-test',
            'created_by'      => $this->user->id,
        ]);

        // ─── Per-transaction double-entry balance ───
        $txSum = DB::table('account_entries')
            ->where('transaction_id', $transfer->transaction_id)
            ->selectRaw('SUM(debit) AS d, SUM(credit) AS c')
            ->first();
        self::assertEqualsWithDelta(0.0, (float) $txSum->d - (float) $txSum->c, 0.01, 'transaction NOT balanced debit/credit');

        // ─── Per-account DRIFT (the project's invariant) ───
        // bank: 30000 - 5000 = 25000
        $this->accounts['bank']->refresh();
        self::assertEqualsWithDelta(25000.0, (float) $this->accounts['bank']->balance, 0.01);
        $this->assertInvariant($this->accounts['bank']);

        // cash1: 5000 + 5000 = 10000
        $this->accounts['cash1']->refresh();
        self::assertEqualsWithDelta(10000.0, (float) $this->accounts['cash1']->balance, 0.01);
        $this->assertInvariant($this->accounts['cash1']);

        // ─── Critical: SOURCE got DEBIT (not CREDIT) ───
        $sourceEntry = DB::table('account_entries')
            ->where('transaction_id', $transfer->transaction_id)
            ->where('account_id', $this->accounts['bank']->id)
            ->first();

        self::assertEquals(
            0.0,
            (float) $sourceEntry->credit,
            'REGRESSION: source account has CREDIT — should be DEBIT (project invariant)'
        );
        self::assertEqualsWithDelta(
            5000.0,
            (float) $sourceEntry->debit,
            0.01,
            'REGRESSION: source account DEBIT must equal transfer amount'
        );

        // ─── Critical: DESTINATION got CREDIT (not DEBIT) ───
        $destEntry = DB::table('account_entries')
            ->where('transaction_id', $transfer->transaction_id)
            ->where('account_id', $this->accounts['cash1']->id)
            ->first();

        self::assertEquals(
            0.0,
            (float) $destEntry->debit,
            'REGRESSION: destination account has DEBIT — should be CREDIT'
        );
        self::assertEqualsWithDelta(
            5000.0,
            (float) $destEntry->credit,
            0.01,
            'REGRESSION: destination account CREDIT must equal transfer amount'
        );
    }

    /**
     * ─────────────────────────────────────────────────────────────
     * ASSERT 3: `recordJournalTransfer()` (the lower-level journal
     *           variant) writes entries in the correct direction.
     * ─────────────────────────────────────────────────────────────
     */
    public function test_record_journal_transfer_writes_entries_in_correct_direction(): void
    {
        $transferService = app(TransactionService::class);

        $tx = $transferService->recordJournalTransfer([
            'from_account_id' => $this->accounts['bank']->id,
            'to_account_id'   => $this->accounts['cash2']->id,
            'amount'          => 1500.0,
            'module'          => 'office',
            'notes'           => 'regression-journal-test',
            'created_by'      => $this->user->id,
            'allow_from_negative' => false,
        ]);

        $sums = DB::table('account_entries')
            ->where('transaction_id', $tx->id)
            ->selectRaw('SUM(debit) AS d, SUM(credit) AS c')
            ->first();
        self::assertEqualsWithDelta(0.0, (float) $sums->d - (float) $sums->c, 0.01);

        $this->accounts['bank']->refresh();
        self::assertEqualsWithDelta(28500.0, (float) $this->accounts['bank']->balance, 0.01);
        $this->assertInvariant($this->accounts['bank']);

        $this->accounts['cash2']->refresh();
        self::assertEqualsWithDelta(3500.0, (float) $this->accounts['cash2']->balance, 0.01);
        $this->assertInvariant($this->accounts['cash2']);
    }

    /**
     * ─────────────────────────────────────────────────────────────
     * ASSERT 4: Multiple transfers maintain the invariant (chain
     *           of operations on the same account).
     * ─────────────────────────────────────────────────────────────
     */
    public function test_chain_of_transfers_preserves_invariant(): void
    {
        $transferService = app(TransactionService::class);

        // Move 1000 → cash1
        $transferService->recordTransfer([
            'from_account_id' => $this->accounts['bank']->id,
            'to_account_id'   => $this->accounts['cash1']->id,
            'amount'          => 1000,
            'currency'        => 'EGP',
            'module'          => 'office',
            'created_by'      => $this->user->id,
        ]);

        // Move 500 from cash1 → cash2
        $transferService->recordTransfer([
            'from_account_id' => $this->accounts['cash1']->id,
            'to_account_id'   => $this->accounts['cash2']->id,
            'amount'          => 500,
            'currency'        => 'EGP',
            'module'          => 'office',
            'created_by'      => $this->user->id,
        ]);

        // Expected final balances:
        //   bank  : 30000 - 1000 = 29000
        //   cash1 : 5000 + 1000 - 500 = 5500
        //   cash2 : 2000 + 500 = 2500

        $this->accounts['bank']->refresh();
        self::assertEqualsWithDelta(29000.0, (float) $this->accounts['bank']->balance, 0.01);
        $this->assertInvariant($this->accounts['bank']);

        $this->accounts['cash1']->refresh();
        self::assertEqualsWithDelta(5500.0, (float) $this->accounts['cash1']->balance, 0.01);
        $this->assertInvariant($this->accounts['cash1']);

        $this->accounts['cash2']->refresh();
        self::assertEqualsWithDelta(2500.0, (float) $this->accounts['cash2']->balance, 0.01);
        $this->assertInvariant($this->accounts['cash2']);
    }

    /**
     * ─────────────────────────────────────────────────────────────
     * ASSERT 5: Insufficient balance rejection preserves invariant
     *           (no partial / failed transfer can corrupt the ledger).
     * ─────────────────────────────────────────────────────────────
     */
    public function test_failed_transfer_does_not_violate_invariant(): void
    {
        $transferService = app(TransactionService::class);

        // Try to over-spend cash2 (balance 2000)
        $threw = false;
        try {
            $transferService->recordTransfer([
                'from_account_id' => $this->accounts['cash2']->id,
                'to_account_id'   => $this->accounts['bank']->id,
                'amount'          => 99999.0,
                'currency'        => 'EGP',
                'module'          => 'office',
                'created_by'      => $this->user->id,
            ]);
        } catch (\Throwable $e) {
            $threw = true;
        }

        self::assertTrue($threw, 'Over-spend should have thrown');

        // cash2 balance should still be 2000 (no partial mutation)
        $this->accounts['cash2']->refresh();
        self::assertEqualsWithDelta(2000.0, (float) $this->accounts['cash2']->balance, 0.01);
        $this->assertInvariant($this->accounts['cash2']);

        // bank balance unchanged
        $this->accounts['bank']->refresh();
        self::assertEqualsWithDelta(30000.0, (float) $this->accounts['bank']->balance, 0.01);
        $this->assertInvariant($this->accounts['bank']);
    }

    /**
     * ─────────────────────────────────────────────────────────────
     * ASSERT 6: Every transaction in the database has equal sum of
     *           debits and credits (double-entry balance).
     * ─────────────────────────────────────────────────────────────
     */
    public function test_every_transaction_has_balanced_entries(): void
    {
        // Generate a few real transfers so we have real transactions
        $transferService = app(TransactionService::class);
        $transferService->recordTransfer([
            'from_account_id' => $this->accounts['bank']->id,
            'to_account_id'   => $this->accounts['cash1']->id,
            'amount'          => 500,
            'currency'        => 'EGP',
            'module'          => 'office',
            'created_by'      => $this->user->id,
        ]);
        $transferService->recordTransfer([
            'from_account_id' => $this->accounts['bank']->id,
            'to_account_id'   => $this->accounts['cash2']->id,
            'amount'          => 700,
            'currency'        => 'EGP',
            'module'          => 'office',
            'created_by'      => $this->user->id,
        ]);

        // Note: opening entries have transaction_id = NULL; exclude them
        // so we don't accidentally lump them into one "transaction" bucket.
        $imbalanced = DB::table('account_entries')
            ->whereNotNull('transaction_id')
            ->select('transaction_id')
            ->selectRaw('SUM(debit) AS d, SUM(credit) AS c, COUNT(*) AS line_count')
            ->groupBy('transaction_id')
            ->havingRaw('ABS(SUM(debit) - SUM(credit)) > 0.01')
            ->get();

        self::assertCount(
            0,
            $imbalanced,
            'Found transactions with unbalanced debit/credit: '
            . $imbalanced->map(fn ($r) => "tx={$r->transaction_id} d={$r->d} c={$r->c}")->implode(', ')
        );
    }

    /**
     * A reversal must swap each ledger leg and restore both account balances.
     */
    public function test_reversal_preserves_account_balance_invariant(): void
    {
        $transactionService = app(TransactionService::class);

        $transfer = $transactionService->recordJournalTransfer([
            'from_account_id' => $this->accounts['bank']->id,
            'to_account_id' => $this->accounts['cash1']->id,
            'amount' => 1200.00,
            'module' => 'office',
            'created_by' => $this->user->id,
        ]);

        $transactionService->reverseTransaction($transfer);

        $this->accounts['bank']->refresh();
        $this->accounts['cash1']->refresh();
        self::assertEqualsWithDelta(30000.00, (float) $this->accounts['bank']->balance, 0.01);
        self::assertEqualsWithDelta(5000.00, (float) $this->accounts['cash1']->balance, 0.01);
        $this->assertInvariant($this->accounts['bank']);
        $this->assertInvariant($this->accounts['cash1']);

        $entries = AccountEntry::query()
            ->where('transaction_id', $transfer->id)
            ->orderBy('id')
            ->get();
        self::assertCount(4, $entries);
        self::assertEqualsWithDelta((float) $entries[0]->debit, (float) $entries[2]->credit, 0.01);
        self::assertEqualsWithDelta((float) $entries[0]->credit, (float) $entries[2]->debit, 0.01);
        self::assertEqualsWithDelta((float) $entries[1]->debit, (float) $entries[3]->credit, 0.01);
        self::assertEqualsWithDelta((float) $entries[1]->credit, (float) $entries[3]->debit, 0.01);

        // Idempotency contract: a second reverseTransaction() on an already
        // reversed transaction must be a no-op (no extra ledger entries,
        // balances unchanged) rather than throwing. See the dedicated
        // test_reverse_transaction_is_idempotent_on_double_call below.
        $result = $transactionService->reverseTransaction($transfer->fresh());
        self::assertSame($transfer->id, $result->id);

        $entriesAfterDouble = AccountEntry::query()
            ->where('transaction_id', $transfer->id)
            ->orderBy('id')
            ->get();
        self::assertCount(4, $entriesAfterDouble, 'No extra ledger entries from a double reversal.');
    }

    /**
     * Calling reverseTransaction() on an already-reversed transaction is a
     * no-op (idempotent) — it returns the original transaction unchanged
     * without adding more ledger entries or double-adjusting account balances.
     */
    public function test_reverse_transaction_is_idempotent_on_double_call(): void
    {
        $transactionService = app(TransactionService::class);

        $transfer = $transactionService->recordJournalTransfer([
            'from_account_id' => $this->accounts['bank']->id,
            'to_account_id' => $this->accounts['cash1']->id,
            'amount' => 800.00,
            'module' => 'office',
            'created_by' => $this->user->id,
        ]);

        // 1st reversal: creates the inverse entries.
        $transactionService->reverseTransaction($transfer);

        $entriesAfterFirst = AccountEntry::query()
            ->where('transaction_id', $transfer->id)
            ->orderBy('id')
            ->get();

        self::assertCount(4, $entriesAfterFirst);
        $balancesAfterFirst = [
            'bank' => (float) $this->accounts['bank']->fresh()->balance,
            'cash1' => (float) $this->accounts['cash1']->fresh()->balance,
        ];

        // 2nd reversal must be a no-op.
        $result = $transactionService->reverseTransaction($transfer->fresh());
        self::assertSame($transfer->id, $result->id);

        $entriesAfterSecond = AccountEntry::query()
            ->where('transaction_id', $transfer->id)
            ->orderBy('id')
            ->get();
        self::assertCount(4, $entriesAfterSecond, 'Idempotent: no extra ledger entries were created.');

        self::assertEqualsWithDelta(
            $balancesAfterFirst['bank'],
            (float) $this->accounts['bank']->fresh()->balance,
            0.01,
            'Bank balance unchanged after idempotent second reversal.'
        );
        self::assertEqualsWithDelta(
            $balancesAfterFirst['cash1'],
            (float) $this->accounts['cash1']->fresh()->balance,
            0.01,
            'Cash1 balance unchanged after idempotent second reversal.'
        );
    }

    /**
     * A converted destination amount must be reversed using its own ledger value.
     */
    public function test_multi_currency_reversal_uses_each_ledger_leg_amount(): void
    {
        $usd = Account::create([
            'name' => 'Test Cashbox USD',
            'type' => AccountType::Cashbox,
            'currency' => 'USD',
            'balance' => 0,
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'office',
            'is_module_vault' => false,
            'created_by' => $this->user->id,
        ]);
        $this->openWith($usd, 100);

        $transactionService = app(TransactionService::class);
        $transaction = $transactionService->recordJournalTransfer([
            'from_account_id' => $this->accounts['bank']->id,
            'to_account_id' => $usd->id,
            'amount' => 3000.00,
            'converted_amount' => 100.00,
            'exchange_rate' => 30.0,
            'module' => 'office',
            'created_by' => $this->user->id,
        ]);

        $transactionService->reverseTransaction($transaction);

        $this->assertInvariant($this->accounts['bank']);
        $this->assertInvariant($usd);
        self::assertEqualsWithDelta(30000.00, (float) $this->accounts['bank']->fresh()->balance, 0.01);
        self::assertEqualsWithDelta(100.00, (float) $usd->fresh()->balance, 0.01);
    }

    /**
     * ─────────────────────────────────────────────────────────────
     * ASSERT 7: Direct `Account::balance` writes outside the
     *           canonical services are FORBIDDEN (the booted-guard).
     * ─────────────────────────────────────────────────────────────
     */
    public function test_direct_balance_write_is_blocked_outside_guard(): void
    {
        $account = $this->accounts['bank'];
        $account->balance = 9999.99;

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/تعديل رصيد/');
        $account->save();
    }

    /**
     * ─────────────────────────────────────────────────────────────
     * ASSERT 8: Direct balance writes inside `LedgerBalanceMutationGuard`
     *           are allowed (used by reconciliation scripts).
     * ─────────────────────────────────────────────────────────────
     */
    public function test_balance_writes_inside_guard_are_allowed(): void
    {
        $account = $this->accounts['bank'];

        $result = LedgerBalanceMutationGuard::run(function () use ($account) {
            $account->balance = 12345.67;
            return $account->save();
        });

        self::assertTrue($result, 'Save inside guard should return true');
        self::assertEqualsWithDelta(12345.67, (float) $account->fresh()->balance, 0.01);
    }
}
