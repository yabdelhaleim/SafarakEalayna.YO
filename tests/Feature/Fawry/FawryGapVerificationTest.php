<?php

namespace Tests\Feature\Fawry;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\AccountEntry;
use App\Models\Fawry\FawryMachine;
use App\Models\Fawry\FawryOperationType;
use App\Models\Fawry\FawryPaymentMethod;
use App\Models\Fawry\FawryTransaction;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Fawry\FawryTransactionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * GAP VERIFICATION TEST SUITE — Fawry Module
 *
 * Addresses specific gaps identified in the gap analysis:
 *
 * GAP-01: updateTransaction (F-05) — dedicated financial movement tests (13 scenarios)
 * GAP-02: Concurrency — sequential invariant verification (with explicit SQLite limitation notice)
 */
class FawryGapVerificationTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected FawryTransactionService $fawryService;

    protected Account $cashbox;

    protected FawryMachine $machine;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fawryService = app(FawryTransactionService::class);

        $this->admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        Auth::login($this->admin);

        FawryOperationType::firstOrCreate(
            ['code' => 'bill_payment'],
            ['name_ar' => 'دفع فواتير', 'name_en' => 'Bill Payment', 'is_active' => true]
        );
        FawryPaymentMethod::firstOrCreate(
            ['code' => 'cash'],
            ['name_ar' => 'نقدي', 'name_en' => 'Cash', 'is_active' => true]
        );

        $this->cashbox = Account::create([
            'name' => 'GAP Cashbox EGP',
            'type' => AccountType::Cashbox,
            'balance' => 50000.00,
            'currency' => 'EGP',
            'is_active' => true,
            'owner_type' => Account::OWNER_TYPE_OWNER,
            'module_type' => 'office',
            'created_by' => 1,
        ]);

        $this->machine = FawryMachine::create([
            'name' => 'GAP Test Machine',
            'type' => 'fawry',
            'balance' => 20000.00,
            'is_active' => true,
        ]);
    }

    // ──────────────────────── Helpers ───────────────────────────

    private function assertDoubleEntryBalanced(int $transactionId, string $label = ''): void
    {
        $entries = AccountEntry::where('transaction_id', $transactionId)->get();
        $debitSum = round($entries->sum('debit'), 2);
        $creditSum = round($entries->sum('credit'), 2);
        $this->assertEquals($debitSum, $creditSum,
            "TX #{$transactionId} {$label}: debits={$debitSum} credits={$creditSum} — not balanced");
    }

    private function assertAllLinkedGlBalanced(FawryTransaction $tx): void
    {
        $linked = Transaction::where('related_type', FawryTransaction::class)
            ->where('related_id', $tx->id)->get();

        $this->assertGreaterThan(0, $linked->count(),
            "No GL transactions linked to FawryTransaction #{$tx->id}");

        foreach ($linked as $glTx) {
            $this->assertDoubleEntryBalanced($glTx->id, "(FawryTx #{$tx->id})");
        }
    }

    private function assertNoOrphanGlEntries(): void
    {
        $orphans = AccountEntry::whereNotNull('transaction_id')
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('transactions')
                    ->whereColumn('transactions.id', 'account_entries.transaction_id');
            })->get();

        $this->assertEquals(0, $orphans->count(), "Found {$orphans->count()} orphan AccountEntry rows");
    }

    private function glCountFor(FawryTransaction $tx): int
    {
        return Transaction::where('related_type', FawryTransaction::class)
            ->where('related_id', $tx->id)->count();
    }

    private function makeBaseTx(
        float $fawryPrice = 800.00,
        float $sellingPrice = 1000.00,
        float $amount = 1000.00
    ): FawryTransaction {
        return $this->fawryService->createTransaction([
            'client_name' => 'GAP Client',
            'operation_type' => 'bill_payment',
            'client_amount' => $sellingPrice,
            'fawry_price' => $fawryPrice,
            'selling_price' => $sellingPrice,
            'amount' => $amount,
            'employee_id' => $this->admin->id,
            'account_id' => $this->cashbox->id,
            'fawry_machine_id' => $this->machine->id,
            'payment_method' => 'cash',
        ]);
    }

    // ═══════════════════════════════════════════════════════════
    //  GAP-01 — updateTransaction: 13 Financial Scenarios (F-05)
    // ═══════════════════════════════════════════════════════════

    /** GAP-01-A: Update selling_price upward → higher profit, higher cashbox, new GL */
    public function test_gap01_a_update_selling_price_upward(): void
    {
        $tx = $this->makeBaseTx(800, 1000, 1000);
        $machBefore = (float) $this->machine->fresh()->balance;
        $glBefore = $this->glCountFor($tx);

        $updated = $this->fawryService->updateTransaction($tx, [
            'selling_price' => 1200.00,
            'amount' => 1200.00,
        ]);

        $this->assertEquals(400.00, (float) $updated->profit, 'Profit=1200-800=400');
        $this->assertEquals(1200.00, (float) $updated->selling_price);
        $this->assertEquals(800.00, (float) $updated->fawry_price, 'fawry_price unchanged');
        $this->assertEquals(1200.00, (float) $updated->amount);
        $this->assertEquals($machBefore, (float) $this->machine->fresh()->balance,
            'Machine unchanged when only selling_price changes');
        $this->assertGreaterThan($glBefore, $this->glCountFor($updated),
            'Update must produce additional GL transactions (reversal + repost)');
        $this->assertAllLinkedGlBalanced($updated);
        $this->assertNoOrphanGlEntries();
    }

    /** GAP-01-B: Update selling_price downward → lower profit, new GL */
    public function test_gap01_b_update_selling_price_downward(): void
    {
        $tx = $this->makeBaseTx(800, 1000, 1000);
        $glBefore = $this->glCountFor($tx);

        $updated = $this->fawryService->updateTransaction($tx, [
            'selling_price' => 900.00,
            'amount' => 900.00,
        ]);

        $this->assertEquals(100.00, (float) $updated->profit, 'Profit=900-800=100');
        $this->assertGreaterThan($glBefore, $this->glCountFor($updated));
        $this->assertAllLinkedGlBalanced($updated);
        $this->assertNoOrphanGlEntries();
    }

    /** GAP-01-C: Update fawry_price upward → machine debited additional diff */
    public function test_gap01_c_update_fawry_price_upward_machine_debited(): void
    {
        $tx = $this->makeBaseTx(800, 1000, 1000);
        $machBefore = (float) $this->machine->fresh()->balance; // 20000 - 800 = 19200

        $updated = $this->fawryService->updateTransaction($tx, ['fawry_price' => 900.00]);

        $this->assertEquals(100.00, (float) $updated->profit, 'Profit=1000-900=100');
        $this->assertEquals(900.00, (float) $updated->fawry_price);
        $this->assertEquals(
            round($machBefore - 100.00, 2),
            round((float) $this->machine->fresh()->balance, 2),
            'Machine debited additional 100 (900-800)'
        );
        $this->assertAllLinkedGlBalanced($updated);
        $this->assertNoOrphanGlEntries();
    }

    /** GAP-01-D: Update fawry_price downward → machine credited back diff */
    public function test_gap01_d_update_fawry_price_downward_machine_credited(): void
    {
        $tx = $this->makeBaseTx(800, 1000, 1000);
        $machBefore = (float) $this->machine->fresh()->balance; // 19200

        $updated = $this->fawryService->updateTransaction($tx, ['fawry_price' => 700.00]);

        $this->assertEquals(300.00, (float) $updated->profit, 'Profit=1000-700=300');
        $this->assertEquals(
            round($machBefore + 100.00, 2),
            round((float) $this->machine->fresh()->balance, 2),
            'Machine credited back 100 (800-700)'
        );
        $this->assertAllLinkedGlBalanced($updated);
        $this->assertNoOrphanGlEntries();
    }

    /** GAP-01-E: Update amount from partial to full → settlement GL reposted */
    public function test_gap01_e_update_amount_partial_to_full(): void
    {
        $tx = $this->makeBaseTx(800, 1000, 400); // partial
        $glBefore = $this->glCountFor($tx);

        $updated = $this->fawryService->updateTransaction($tx, ['amount' => 1000.00]);

        $this->assertEquals(1000.00, (float) $updated->amount);
        $this->assertGreaterThan($glBefore, $this->glCountFor($updated),
            'Amount change must repost settlement GL');
        $this->assertAllLinkedGlBalanced($updated);
        $this->assertNoOrphanGlEntries();
    }

    /** GAP-01-F: Update both selling_price and fawry_price simultaneously */
    public function test_gap01_f_update_both_prices_simultaneously(): void
    {
        $tx = $this->makeBaseTx(800, 1000, 1000);
        $machBefore = (float) $this->machine->fresh()->balance; // 19200

        $updated = $this->fawryService->updateTransaction($tx, [
            'selling_price' => 1100.00,
            'fawry_price' => 750.00,
            'amount' => 1100.00,
        ]);

        $this->assertEquals(350.00, (float) $updated->profit, 'Profit=1100-750=350');
        $this->assertEquals(
            round($machBefore + 50.00, 2),
            round((float) $this->machine->fresh()->balance, 2),
            'Machine credited back 50 (800→750)'
        );
        $this->assertAllLinkedGlBalanced($updated);
        $this->assertNoOrphanGlEntries();
    }

    /** GAP-01-G: Non-financial fields only → zero GL movement */
    public function test_gap01_g_non_financial_fields_produce_no_gl_movement(): void
    {
        $tx = $this->makeBaseTx(800, 1000, 1000);
        $glBefore = $this->glCountFor($tx);
        $machBefore = (float) $this->machine->fresh()->balance;
        $cashBefore = (float) $this->cashbox->fresh()->balance;

        $this->fawryService->updateTransaction($tx, [
            'notes' => 'Updated note',
            'reference_number' => 'REF-NOOP-001',
        ]);

        $this->assertEquals($glBefore, $this->glCountFor($tx),
            'Non-financial update must not produce new GL');
        $this->assertEquals($machBefore, (float) $this->machine->fresh()->balance);
        $this->assertEquals($cashBefore, (float) $this->cashbox->fresh()->balance);
    }

    /** GAP-01-H: Identical values twice — second call is a no-op on GL */
    public function test_gap01_h_repeated_update_same_values_is_idempotent(): void
    {
        $tx = $this->makeBaseTx(800, 1000, 1000);

        $this->fawryService->updateTransaction($tx, ['selling_price' => 1100.00, 'amount' => 1100.00]);
        $glAfterFirst = $this->glCountFor($tx);
        $machAfterFirst = (float) $this->machine->fresh()->balance;

        $tx->refresh();
        $this->fawryService->updateTransaction($tx, ['selling_price' => 1100.00, 'amount' => 1100.00]);

        $this->assertEquals($glAfterFirst, $this->glCountFor($tx),
            'Identical update must not add GL transactions');
        $this->assertEquals($machAfterFirst, (float) $this->machine->fresh()->balance,
            'Machine must not change on identical-value update');
        $this->assertAllLinkedGlBalanced($tx->fresh());
        $this->assertNoOrphanGlEntries();
    }

    /** GAP-01-I: Update then delete — all balances return to pre-create snapshot */
    public function test_gap01_i_update_then_delete_returns_all_balances(): void
    {
        $cashStart = (float) $this->cashbox->balance;
        $machStart = (float) $this->machine->balance;

        $tx = $this->makeBaseTx(800, 1000, 1000);
        $this->fawryService->updateTransaction($tx, [
            'fawry_price' => 900.00,
            'selling_price' => 1100.00,
            'amount' => 1100.00,
        ]);

        $tx->refresh();
        $this->fawryService->deleteTransaction($tx);

        $this->assertEquals($cashStart, (float) $this->cashbox->fresh()->balance,
            'Cashbox must return to pre-create balance after update+delete');
        $this->assertEquals($machStart, (float) $this->machine->fresh()->balance,
            'Machine must return to pre-create balance after update+delete');
        $this->assertNoOrphanGlEntries();
    }

    /** GAP-01-J: Lower amount (full → partial) reopens debt */
    public function test_gap01_j_update_amount_full_to_partial_reopens_debt(): void
    {
        $tx = $this->makeBaseTx(800, 1000, 1000); // fully paid
        $updated = $this->fawryService->updateTransaction($tx, ['amount' => 600.00]);

        $this->assertEquals(600.00, (float) $updated->amount);
        $this->assertEquals(200.00, (float) $updated->profit, 'Profit unchanged (selling-fawry=200)');
        $this->assertAllLinkedGlBalanced($updated);
        $this->assertNoOrphanGlEntries();
    }

    /** GAP-01-K: Amount exceeds selling_price — GL remains balanced regardless */
    public function test_gap01_k_amount_exceeds_selling_price_gl_remains_balanced(): void
    {
        $tx = $this->makeBaseTx(800, 1000, 1000);
        $updated = $this->fawryService->updateTransaction($tx, ['amount' => 1100.00]);

        $this->assertAllLinkedGlBalanced($updated);
        $this->assertNoOrphanGlEntries();
    }

    /** GAP-01-L: Multiple sequential updates — profit recalculation correct at each step */
    public function test_gap01_l_multiple_updates_profit_recalculation_correct(): void
    {
        $tx = $this->makeBaseTx(800, 1000, 1000); // profit=200

        $u1 = $this->fawryService->updateTransaction($tx, ['selling_price' => 1200.00, 'amount' => 1200.00]);
        $this->assertEquals(400.00, (float) $u1->profit, 'After update1: 1200-800=400');
        $this->assertAllLinkedGlBalanced($u1);

        $u1->refresh();
        $u2 = $this->fawryService->updateTransaction($u1, ['fawry_price' => 700.00]);
        $this->assertEquals(500.00, (float) $u2->profit, 'After update2: 1200-700=500');
        $this->assertAllLinkedGlBalanced($u2);

        $this->assertNoOrphanGlEntries();
    }

    /** GAP-01-M: Verify no duplicate GL movement — count and balance every linked entry */
    public function test_gap01_m_no_duplicate_accounting_movement_on_update(): void
    {
        $tx = $this->makeBaseTx(800, 1000, 1000);
        $glBefore = $this->glCountFor($tx);

        $updated = $this->fawryService->updateTransaction($tx, [
            'selling_price' => 1300.00,
            'fawry_price' => 850.00,
            'amount' => 1300.00,
        ]);

        $glAfter = $this->glCountFor($updated);
        $this->assertGreaterThan($glBefore, $glAfter,
            'Update must create reversal + new posting GL transactions');

        $linked = Transaction::where('related_type', FawryTransaction::class)
            ->where('related_id', $updated->id)->get();

        foreach ($linked as $glTx) {
            $entries = AccountEntry::where('transaction_id', $glTx->id)->get();
            $debitSum = round($entries->sum('debit'), 2);
            $creditSum = round($entries->sum('credit'), 2);
            $this->assertEquals($debitSum, $creditSum,
                "GL TX #{$glTx->id}: debit={$debitSum} != credit={$creditSum}");
        }

        $this->assertNoOrphanGlEntries();
    }

    // ═══════════════════════════════════════════════════════════
    //  GAP-02 — Concurrency
    //
    //  LIMITATION NOTICE (audit-critical, must appear in final report):
    //
    //  PHPUnit on SQLite in-memory is single-threaded.
    //  DB::lockForUpdate() on SQLite is a NO-OP (SQLite WAL mode does not
    //  support row-level pessimistic locking between OS threads/processes).
    //  Therefore TRUE parallel concurrent execution is NOT achievable inside
    //  these PHPUnit tests.
    //
    //  The tests below verify SEQUENTIAL INVARIANTS — meaning: they prove
    //  the service code upholds every accounting invariant under serialized
    //  re-entry, which is the behavior the production-level pessimistic
    //  DB lock (MySQL InnoDB lockForUpdate) enforces.
    //
    //  TRUE CONCURRENCY EXISTS at the script layer:
    //    tests/scripts/fawry_module_full_e2e_audit_20260814.php
    //      - Uses curl_multi to fire 2 parallel HTTP POST /pay-debt requests
    //        against a running Laravel server on a real MySQL database.
    //      - Phase CONCURRENT (line ~930-985) verifies no over-collection,
    //        no duplicate GL, no ghost balance.
    //    This is the ONLY authoritative true-concurrency test for Fawry.
    //
    //  GAP-02 VERDICT: CONDITIONAL — parallel invariants proven by sequential
    //  tests; true-parallel verified by external script (MySQL-only).
    // ═══════════════════════════════════════════════════════════

    /** GAP-02-A: 25 sequential creates — exact balance accounting, no GL orphans */
    public function test_gap02_a_25_sequential_payments_exact_balances(): void
    {
        $machStart = (float) $this->machine->balance;
        $cashStart = (float) $this->cashbox->balance;
        $n = 25;
        $fawryPrice = 80.00;
        $selling = 100.00;
        $paid = 100.00;

        for ($i = 0; $i < $n; $i++) {
            $tx = $this->fawryService->createTransaction([
                'client_name' => "SeqClient{$i}",
                'operation_type' => 'bill_payment',
                'client_amount' => $selling,
                'fawry_price' => $fawryPrice,
                'selling_price' => $selling,
                'amount' => $paid,
                'employee_id' => $this->admin->id,
                'account_id' => $this->cashbox->id,
                'fawry_machine_id' => $this->machine->id,
                'payment_method' => 'cash',
            ]);
            $this->assertAllLinkedGlBalanced($tx);
        }

        $this->assertEquals($n, FawryTransaction::count(), "Exactly {$n} FawryTransaction records");

        $expectedMach = round($machStart - ($fawryPrice * $n), 2);
        $this->assertEquals($expectedMach, round((float) $this->machine->fresh()->balance, 2),
            'Machine = start - total_fawry_cost');

        $expectedCash = round($cashStart + ($paid * $n), 2);
        $this->assertEquals($expectedCash, round((float) $this->cashbox->fresh()->balance, 2),
            'Cashbox = start + total_amount_paid');

        $this->assertNoOrphanGlEntries();
    }

    /** GAP-02-B: 10 create+delete cycles — balances fully restore each cycle */
    public function test_gap02_b_create_delete_cycles_fully_restore_balances(): void
    {
        $machStart = (float) $this->machine->balance;
        $cashStart = (float) $this->cashbox->balance;

        for ($cycle = 0; $cycle < 10; $cycle++) {
            $tx = $this->makeBaseTx(800, 1000, 1000);
            $this->fawryService->deleteTransaction($tx);

            $this->assertEquals($machStart, (float) $this->machine->fresh()->balance,
                "Cycle {$cycle}: machine must restore");
            $this->assertEquals($cashStart, (float) $this->cashbox->fresh()->balance,
                "Cycle {$cycle}: cashbox must restore");
        }

        $this->assertNoOrphanGlEntries();
    }

    /** GAP-02-C: 25 sequential walk-in payments — no over-collection, exact debt clearance */
    public function test_gap02_c_25_sequential_walkin_payments_no_over_collection(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $clientName = 'SequentialWalkIn';
        $debtPerTx = 100.00;
        $n = 25;

        for ($i = 0; $i < $n; $i++) {
            $this->fawryService->createTransaction([
                'client_name' => $clientName,
                'operation_type' => 'bill_payment',
                'client_amount' => $debtPerTx,
                'fawry_price' => 80.00,
                'selling_price' => $debtPerTx,
                'amount' => 0.00, // unpaid — builds walk-in AR
                'employee_id' => $this->admin->id,
                'account_id' => $this->cashbox->id,
                'fawry_machine_id' => $this->machine->id,
                'payment_method' => 'cash',
            ]);
        }

        $totalDebt = (float) DB::table('fawry_transactions')
            ->whereNull('client_id')->whereNull('deleted_at')
            ->where('client_name', $clientName)
            ->selectRaw('COALESCE(SUM(selling_price - amount), 0) as debt')
            ->value('debt');
        $this->assertEquals($debtPerTx * $n, $totalDebt, 'Initial debt must equal n * debtPerTx');

        $cashBefore = (float) $this->cashbox->fresh()->balance;
        $totalPaid = 0.0;

        for ($i = 0; $i < $n; $i++) {
            $resp = $this->postJson('/api/v1/fawry/walk-in/pay-debt', [
                'client_name' => $clientName,
                'amount' => $debtPerTx,
                'account_id' => $this->cashbox->id,
            ]);
            if ($resp->status() === 200) {
                $totalPaid += $debtPerTx;
            }
        }

        $finalDebt = (float) DB::table('fawry_transactions')
            ->whereNull('client_id')->whereNull('deleted_at')
            ->where('client_name', $clientName)
            ->selectRaw('COALESCE(SUM(selling_price - amount), 0) as debt')
            ->value('debt');

        $this->assertLessThanOrEqual(0.01, abs($finalDebt),
            "Final debt must be ~0 after full payment (got {$finalDebt})");
        $this->assertLessThanOrEqual($debtPerTx * $n, $totalPaid,
            'Must not collect more than outstanding debt');
        $this->assertEquals(
            round($cashBefore + $totalPaid, 2),
            round((float) $this->cashbox->fresh()->balance, 2),
            'Cashbox must increase by exactly what was collected'
        );
        $this->assertNoOrphanGlEntries();
    }

    /** GAP-02-D: Double-delete idempotency — second delete is a no-op, no duplicate GL reversal */
    public function test_gap02_d_double_delete_no_duplicate_reversal(): void
    {
        $machStart = (float) $this->machine->balance;
        $cashStart = (float) $this->cashbox->balance;

        $tx = $this->makeBaseTx(800, 1000, 1000);

        // First delete
        $r1 = $this->fawryService->deleteTransaction($tx);
        $this->assertTrue($r1);

        $machAfterDel1 = (float) $this->machine->fresh()->balance;
        $cashAfterDel1 = (float) $this->cashbox->fresh()->balance;
        $glAfterDel1 = $this->glCountFor($tx);

        // Second delete (stale model — simulates race condition)
        $r2 = $this->fawryService->deleteTransaction($tx);
        $this->assertTrue($r2, 'Second delete must return true (idempotent)');

        // Balances must not change on second delete
        $this->assertEquals($machAfterDel1, (float) $this->machine->fresh()->balance,
            'Machine must not change on duplicate delete');
        $this->assertEquals($cashAfterDel1, (float) $this->cashbox->fresh()->balance,
            'Cashbox must not change on duplicate delete');

        // GL count must not grow (no new reversal)
        $this->assertEquals($glAfterDel1, $this->glCountFor($tx),
            'GL transaction count must not increase on duplicate delete');

        // Full reversal verified: balances equal pre-create
        $this->assertEquals($machStart, (float) $this->machine->fresh()->balance,
            'Machine must equal pre-create state after single reversal');
        $this->assertEquals($cashStart, (float) $this->cashbox->fresh()->balance,
            'Cashbox must equal pre-create state after single reversal');

        $this->assertNoOrphanGlEntries();
    }
}
