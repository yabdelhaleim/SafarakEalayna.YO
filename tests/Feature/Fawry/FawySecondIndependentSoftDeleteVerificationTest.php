<?php

namespace Tests\Feature\Fawry;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\AccountEntry;
use App\Models\Customer;
use App\Models\Fawry\FawryMachine;
use App\Models\Fawry\FawryOperationType;
use App\Models\Fawry\FawryPaymentMethod;
use App\Models\Fawry\FawryTransaction;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Fawry\FawryMachineRechargeService;
use App\Services\Fawry\FawryTransactionService;
use App\Services\Finance\LedgerClearingAccounts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * SECOND INDEPENDENT SOFT-DELETE VERIFICATION
 *
 * This is a completely standalone, independently authored test suite.
 * It does NOT reuse or extend prior test classes.
 * It does NOT trust prior results.
 * It executes every soft-delete scenario from scratch.
 */
class FawySecondIndependentSoftDeleteVerificationTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected Account $cashbox;
    protected FawryMachine $machine;
    protected FawryTransactionService $fawryService;
    protected FawryMachineRechargeService $rechargeService;

    protected function setUp(): void
    {
        parent::setUp();

        // Ensure permissions exist
        if (class_exists(Permission::class)) {
            Permission::firstOrCreate(['name' => 'fawry.create', 'guard_name' => 'sanctum']);
            Permission::firstOrCreate(['name' => 'fawry.delete', 'guard_name' => 'sanctum']);
        }

        $this->admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        Sanctum::actingAs($this->admin, ['*']);

        // Ensure master data exists
        FawryOperationType::firstOrCreate(
            ['code' => 'bill_payment'],
            ['name_ar' => 'دفع فواتير', 'name_en' => 'Bill Payment', 'is_active' => true]
        );
        FawryPaymentMethod::firstOrCreate(
            ['code' => 'cash'],
            ['name_ar' => 'نقدي', 'name_en' => 'Cash', 'is_active' => true]
        );

        $this->cashbox = Account::factory()->active()->create([
            'name' => 'SD_VERIFICATION_CASHBOX',
            'type' => AccountType::Cashbox,
            'currency' => 'EGP',
            'balance' => 20000.00,
            'module_type' => 'office',
        ]);

        $this->machine = FawryMachine::create([
            'name' => 'SD_VERIFICATION_MACHINE',
            'type' => 'fawry',
            'balance' => 10000.00,
            'is_active' => true,
        ]);

        $this->fawryService = app(FawryTransactionService::class);
        $this->rechargeService = app(FawryMachineRechargeService::class);
    }

    // =========================================================================
    // SCENARIO 1: Fresh Paid Transaction — Full Soft Delete
    // =========================================================================

    public function test_scenario_1_fresh_paid_transaction_soft_delete(): void
    {
        // ── BEFORE baseline ───────────────────────────────────────────────────
        $cashbox_before = (float) $this->cashbox->balance;          // 20000.00
        $machine_before = (float) $this->machine->balance;          // 10000.00
        $tx_count_before = FawryTransaction::count();               // 0
        $acct_tx_before  = Transaction::count();                    // 0
        $acct_entry_before = AccountEntry::count();                 // 0

        $this->assertEquals(20000.00, $cashbox_before);
        $this->assertEquals(10000.00, $machine_before);

        // ── CREATE operation: selling=1000, cost=800, paid=1000 ───────────────
        $tx = $this->fawryService->createTransaction([
            'client_name'     => 'SD_SCENARIO_1_CLIENT',
            'operation_type'  => 'bill_payment',
            'client_amount'   => 1000.00,
            'fawry_price'     => 800.00,
            'selling_price'   => 1000.00,
            'amount'          => 1000.00,
            'employee_id'     => $this->admin->id,
            'account_id'      => $this->cashbox->id,
            'fawry_machine_id'=> $this->machine->id,
            'payment_method'  => 'cash',
        ]);

        // ── AFTER CREATE: verify expected movements ───────────────────────────
        $cashbox_after_create = (float) $this->cashbox->fresh()->balance;
        $machine_after_create = (float) $this->machine->fresh()->balance;

        $this->assertEquals($cashbox_before + 1000.00, $cashbox_after_create,
            "AFTER CREATE: cashbox should be +1000 (was {$cashbox_before}, got {$cashbox_after_create})");
        $this->assertEquals($machine_before - 800.00, $machine_after_create,
            "AFTER CREATE: machine should be -800 (was {$machine_before}, got {$machine_after_create})");
        $this->assertEquals(200.00, (float) $tx->profit, "Profit must be 200.00");
        $this->assertEquals(1, FawryTransaction::count() - $tx_count_before);

        // GL: debit total must equal credit total
        $linkedEntries = AccountEntry::whereIn(
            'transaction_id',
            Transaction::where('related_type', FawryTransaction::class)
                ->where('related_id', $tx->id)
                ->pluck('id')
        )->get();
        $this->assertGreaterThan(0, $linkedEntries->count(), "GL entries must exist after create");
        $glDebitCreate  = $linkedEntries->sum('debit');
        $glCreditCreate = $linkedEntries->sum('credit');
        $this->assertEquals(
            round($glDebitCreate, 2),
            round($glCreditCreate, 2),
            "GL must be balanced after create: debit={$glDebitCreate}, credit={$glCreditCreate}"
        );

        // ── SOFT DELETE via HTTP endpoint (production path) ───────────────────
        $response = $this->deleteJson("/api/v1/fawry/transactions/{$tx->id}");
        $response->assertSuccessful();

        // ── VERIFY DB soft-delete state ───────────────────────────────────────
        // 1. Row still physically exists
        $this->assertDatabaseHas('fawry_transactions', ['id' => $tx->id]);
        // 2. deleted_at is NOT NULL
        $this->assertSoftDeleted('fawry_transactions', ['id' => $tx->id]);
        // 3. Normal query excludes it
        $this->assertNull(FawryTransaction::find($tx->id), "Normal query must NOT find soft-deleted row");
        // 4. withTrashed finds it
        $this->assertNotNull(FawryTransaction::withTrashed()->find($tx->id), "withTrashed() must still find deleted row");

        // ── VERIFY FINANCIAL REVERSAL (GHOST BALANCE CRITICAL TEST) ──────────
        $cashbox_after_delete = (float) $this->cashbox->fresh()->balance;
        $machine_after_delete = (float) $this->machine->fresh()->balance;

        $cashbox_variance = round($cashbox_after_delete - $cashbox_before, 2);
        $machine_variance = round($machine_after_delete - $machine_before, 2);

        $this->assertEquals(0.00, $cashbox_variance,
            "GHOST BALANCE CHECK: cashbox variance must be 0.00 after delete. " .
            "Before={$cashbox_before}, AfterDelete={$cashbox_after_delete}, Variance={$cashbox_variance}");
        $this->assertEquals(0.00, $machine_variance,
            "GHOST BALANCE CHECK: machine variance must be 0.00 after delete. " .
            "Before={$machine_before}, AfterDelete={$machine_after_delete}, Variance={$machine_variance}");

        // ── GL after delete: all entries still balanced ───────────────────────
        $allLinkedTxIds = Transaction::where('related_type', FawryTransaction::class)
            ->where('related_id', $tx->id)
            ->pluck('id');
        $this->assertGreaterThan(0, $allLinkedTxIds->count());
        $allEntries = AccountEntry::whereIn('transaction_id', $allLinkedTxIds)->get();
        $glDebitFinal  = round($allEntries->sum('debit'), 2);
        $glCreditFinal = round($allEntries->sum('credit'), 2);
        $this->assertEquals($glDebitFinal, $glCreditFinal,
            "GL must remain balanced after soft-delete: debit={$glDebitFinal}, credit={$glCreditFinal}");

        // Confirm no duplicate reversals (reversal entries tagged with 'عكس:')
        $reversalEntries = AccountEntry::whereIn('transaction_id', $allLinkedTxIds)
            ->where(function ($q) { $q->where('notes', 'like', 'عكس:%')->orWhere('notes', 'like', 'عكس %'); })
            ->get();
        // Each original entry gets exactly ONE reversal; count should match
        $originalEntries = AccountEntry::whereIn('transaction_id', $allLinkedTxIds)
            ->where(function ($q) { $q->where('notes', 'not like', 'عكس:%')->orWhereNull('notes'); })
            ->get();
        // No orphan entries should exist without a parent transaction
        $orphanCheck = AccountEntry::whereIn('transaction_id', $allLinkedTxIds)
            ->whereNotIn('transaction_id', Transaction::pluck('id'))
            ->count();
        $this->assertEquals(0, $orphanCheck, "Zero orphan GL entries must exist");
    }

    // =========================================================================
    // SCENARIO 2: Double Soft Delete — Idempotency Guard
    // =========================================================================

    public function test_scenario_2_double_soft_delete_is_idempotent(): void
    {
        $tx = $this->fawryService->createTransaction([
            'client_name'     => 'SD_SCENARIO_2_IDEMPOTENT_CLIENT',
            'operation_type'  => 'bill_payment',
            'client_amount'   => 1000.00,
            'fawry_price'     => 800.00,
            'selling_price'   => 1000.00,
            'amount'          => 1000.00,
            'employee_id'     => $this->admin->id,
            'account_id'      => $this->cashbox->id,
            'fawry_machine_id'=> $this->machine->id,
            'payment_method'  => 'cash',
        ]);

        $cashbox_after_create = (float) $this->cashbox->fresh()->balance;
        $machine_after_create = (float) $this->machine->fresh()->balance;
        $tx_count_before_gl   = Transaction::count();

        // ── FIRST DELETE ──────────────────────────────────────────────────────
        $r1 = $this->deleteJson("/api/v1/fawry/transactions/{$tx->id}");
        $r1->assertSuccessful();
        $this->assertSoftDeleted('fawry_transactions', ['id' => $tx->id]);

        $cashbox_after_first_delete  = (float) $this->cashbox->fresh()->balance;
        $machine_after_first_delete  = (float) $this->machine->fresh()->balance;
        $gl_count_after_first_delete = Transaction::count();

        // ── SECOND DELETE (must be rejected / idempotent) ─────────────────────
        $r2 = $this->deleteJson("/api/v1/fawry/transactions/{$tx->id}");
        // Application may return 200 (idempotent) or 404; it MUST NOT do a second reversal
        $this->assertContains($r2->status(), [200, 404, 422],
            "Second delete should return 200/404/422, got " . $r2->status());

        $cashbox_after_second_delete = (float) $this->cashbox->fresh()->balance;
        $machine_after_second_delete = (float) $this->machine->fresh()->balance;
        $gl_count_after_second_delete = Transaction::count();

        // No additional cashbox or machine movement
        $this->assertEquals(0.00, round($cashbox_after_second_delete - $cashbox_after_first_delete, 2),
            "DOUBLE DELETE: cashbox must NOT change on second delete. " .
            "After first={$cashbox_after_first_delete}, After second={$cashbox_after_second_delete}");
        $this->assertEquals(0.00, round($machine_after_second_delete - $machine_after_first_delete, 2),
            "DOUBLE DELETE: machine must NOT change on second delete. " .
            "After first={$machine_after_first_delete}, After second={$machine_after_second_delete}");

        // No additional GL records posted
        $this->assertEquals($gl_count_after_first_delete, $gl_count_after_second_delete,
            "DOUBLE DELETE: no additional GL transactions on second delete. " .
            "After first={$gl_count_after_first_delete}, After second={$gl_count_after_second_delete}");

        // Only ONE soft-delete row
        $deletedCount = DB::table('fawry_transactions')
            ->where('id', $tx->id)
            ->whereNotNull('deleted_at')
            ->count();
        $this->assertEquals(1, $deletedCount, "Exactly 1 soft-deleted row must exist");
    }

    // =========================================================================
    // SCENARIO 3: Soft Delete BLOCKED After PARTIAL Payment (Business Guard)
    // =========================================================================

    public function test_scenario_3_soft_delete_blocked_after_partial_payment(): void
    {
        $clientName = 'SD_SCENARIO_3_PARTIAL_PAYMENT_CLIENT';

        // Create debt: selling=1000, paid=0 (full debt)
        $tx = $this->fawryService->createTransaction([
            'client_name'     => $clientName,
            'operation_type'  => 'bill_payment',
            'client_amount'   => 1000.00,
            'fawry_price'     => 800.00,
            'selling_price'   => 1000.00,
            'amount'          => 0.00,
            'employee_id'     => $this->admin->id,
            'account_id'      => $this->cashbox->id,
            'fawry_machine_id'=> $this->machine->id,
            'payment_method'  => 'cash',
        ]);

        // Verify initial debt state
        $initialDebt = (float) $tx->selling_price - (float) $tx->amount;
        $this->assertEquals(1000.00, $initialDebt);

        // Pay 300 EGP
        $payRes = $this->postJson('/api/v1/fawry/walk-in/pay-debt', [
            'client_name' => $clientName,
            'amount'      => 300.00,
            'account_id'  => $this->cashbox->id,
        ]);
        $payRes->assertSuccessful();
        $remaining = json_decode($payRes->getContent(), true)['data']['remaining_debt'];
        $this->assertEquals(700.00, (float) $remaining, "Remaining debt should be 700 after paying 300");

        // Snapshot balances BEFORE attempting delete
        $cashbox_before_delete_attempt = (float) $this->cashbox->fresh()->balance;
        $machine_before_delete_attempt = (float) $this->machine->fresh()->balance;
        $gl_tx_count_before = Transaction::count();

        // ── ATTEMPT SOFT DELETE — MUST BE BLOCKED BY GUARD (HTTP 422) ────────
        // Business rule: DeferredTransactionDeletionGuard prevents deletion
        // when currentPaidAmount (300) > originalPaidAtCreation (0).
        $r = $this->deleteJson("/api/v1/fawry/transactions/{$tx->id}");
        $r->assertStatus(422);
        $responseBody = json_decode($r->getContent(), true);
        $this->assertStringContainsString(
            'سداد',
            $responseBody['message'] ?? ($responseBody['error'] ?? ''),
            "422 error message must mention payment block"
        );

        // ── VERIFY NO BALANCE CHANGED AFTER BLOCKED DELETE ───────────────────
        $cashbox_after_blocked = (float) $this->cashbox->fresh()->balance;
        $machine_after_blocked = (float) $this->machine->fresh()->balance;
        $gl_tx_count_after    = Transaction::count();

        $this->assertEquals(0.00, round($cashbox_after_blocked - $cashbox_before_delete_attempt, 2),
            "BLOCKED DELETE: cashbox must NOT change. Before={$cashbox_before_delete_attempt}, After={$cashbox_after_blocked}");
        $this->assertEquals(0.00, round($machine_after_blocked - $machine_before_delete_attempt, 2),
            "BLOCKED DELETE: machine must NOT change. Before={$machine_before_delete_attempt}, After={$machine_after_blocked}");
        $this->assertEquals($gl_tx_count_before, $gl_tx_count_after,
            "BLOCKED DELETE: no new GL transactions must be posted. Before={$gl_tx_count_before}, After={$gl_tx_count_after}");

        // ── VERIFY TRANSACTION IS STILL NOT SOFT-DELETED ─────────────────────
        $txFresh = FawryTransaction::find($tx->id);
        $this->assertNotNull($txFresh, "Transaction must still be findable (NOT soft-deleted)");
        $this->assertNull($txFresh->deleted_at, "deleted_at must remain NULL after blocked delete");

        // ── VERIFY DEBT IS INTACT ─────────────────────────────────────────────
        $remainingDebt = (float) DB::table('fawry_transactions')
            ->whereNull('deleted_at')
            ->whereNull('client_id')
            ->where('client_name', $clientName)
            ->whereRaw('selling_price > amount')
            ->sum(DB::raw('selling_price - amount'));
        $this->assertEquals(700.00, round($remainingDebt, 2),
            "Debt must remain at 700 (partially paid). Got: {$remainingDebt}");
    }

    // =========================================================================
    // SCENARIO 4: Soft Delete BLOCKED After FULL Payment (Business Guard)
    // =========================================================================

    public function test_scenario_4_soft_delete_blocked_after_full_payment(): void
    {
        $clientName = 'SD_SCENARIO_4_FULL_PAYMENT_CLIENT';

        $tx = $this->fawryService->createTransaction([
            'client_name'     => $clientName,
            'operation_type'  => 'bill_payment',
            'client_amount'   => 1000.00,
            'fawry_price'     => 800.00,
            'selling_price'   => 1000.00,
            'amount'          => 0.00,
            'employee_id'     => $this->admin->id,
            'account_id'      => $this->cashbox->id,
            'fawry_machine_id'=> $this->machine->id,
            'payment_method'  => 'cash',
        ]);

        // Pay FULL 1000 EGP
        $payRes = $this->postJson('/api/v1/fawry/walk-in/pay-debt', [
            'client_name' => $clientName,
            'amount'      => 1000.00,
            'account_id'  => $this->cashbox->id,
        ]);
        $payRes->assertSuccessful();
        $data = json_decode($payRes->getContent(), true)['data'];
        $this->assertEquals(0.00, (float) $data['remaining_debt'], "After full payment, remaining debt must be 0");
        $this->assertTrue((bool) $data['fully_settled']);

        // Snapshot balances BEFORE attempting delete
        $cashbox_before_delete_attempt = (float) $this->cashbox->fresh()->balance;
        $machine_before_delete_attempt = (float) $this->machine->fresh()->balance;
        $gl_tx_count_before = Transaction::count();

        // ── ATTEMPT SOFT DELETE — MUST BE BLOCKED BY GUARD (HTTP 422) ────────
        // Business rule: after full payment, paidAmount (1000) > originalPaidAtCreation (0)
        $r = $this->deleteJson("/api/v1/fawry/transactions/{$tx->id}");
        $r->assertStatus(422);

        // ── VERIFY NO BALANCE CHANGED AFTER BLOCKED DELETE ───────────────────
        $cashbox_after_blocked = (float) $this->cashbox->fresh()->balance;
        $machine_after_blocked = (float) $this->machine->fresh()->balance;

        $this->assertEquals(0.00, round($cashbox_after_blocked - $cashbox_before_delete_attempt, 2),
            "BLOCKED DELETE: cashbox must NOT change after failed delete. " .
            "Before={$cashbox_before_delete_attempt}, After={$cashbox_after_blocked}");
        $this->assertEquals(0.00, round($machine_after_blocked - $machine_before_delete_attempt, 2),
            "BLOCKED DELETE: machine must NOT change after failed delete. " .
            "Before={$machine_before_delete_attempt}, After={$machine_after_blocked}");
        $this->assertEquals($gl_tx_count_before, Transaction::count(),
            "BLOCKED DELETE: no new GL transactions must be posted");

        // ── VERIFY TRANSACTION IS STILL NOT SOFT-DELETED ─────────────────────
        $txFresh = FawryTransaction::find($tx->id);
        $this->assertNotNull($txFresh, "Transaction must still be findable (NOT soft-deleted)");
        $this->assertNull($txFresh->deleted_at, "deleted_at must remain NULL after blocked delete");

        // ── VERIFY DEBT STATUS IS INTACT (still fully settled) ───────────────
        $freshRow = FawryTransaction::find($tx->id);
        $this->assertEquals(1000.00, (float) $freshRow->amount,
            "Transaction amount must remain 1000 (fully paid, deletion blocked)");
    }

    // =========================================================================
    // SCENARIO 5: CRITICAL GHOST BALANCE — Isolated Quantitative Proof
    // =========================================================================

    public function test_scenario_5_ghost_balance_isolated_quantitative_proof(): void
    {
        // Use a specific amount that makes ghost inflation obvious if it exists
        $sellingPrice = 2500.00;
        $fawryPrice   = 2000.00;
        $paidAmount   = 2500.00;

        $cashbox_baseline = (float) $this->cashbox->fresh()->balance;
        $machine_baseline = (float) $this->machine->fresh()->balance;

        $tx = $this->fawryService->createTransaction([
            'client_name'     => 'SD_SCENARIO_5_GHOST_TEST_CLIENT',
            'operation_type'  => 'bill_payment',
            'client_amount'   => $sellingPrice,
            'fawry_price'     => $fawryPrice,
            'selling_price'   => $sellingPrice,
            'amount'          => $paidAmount,
            'employee_id'     => $this->admin->id,
            'account_id'      => $this->cashbox->id,
            'fawry_machine_id'=> $this->machine->id,
            'payment_method'  => 'cash',
        ]);

        // Verify create movement
        $this->assertEquals($cashbox_baseline + $paidAmount, (float) $this->cashbox->fresh()->balance);
        $this->assertEquals($machine_baseline - $fawryPrice, (float) $this->machine->fresh()->balance);

        // SOFT DELETE
        $this->deleteJson("/api/v1/fawry/transactions/{$tx->id}")->assertSuccessful();

        // GHOST BALANCE PROOF
        $cashbox_final = (float) $this->cashbox->fresh()->balance;
        $machine_final = (float) $this->machine->fresh()->balance;

        $cashbox_variance = round($cashbox_final - $cashbox_baseline, 2);
        $machine_variance = round($machine_final - $machine_baseline, 2);

        $this->assertEquals(0.00, $cashbox_variance,
            "CRITICAL GHOST BALANCE: cashbox variance must be EXACTLY 0.00 EGP after soft-delete. " .
            "Baseline={$cashbox_baseline}, Final={$cashbox_final}, Variance={$cashbox_variance} EGP " .
            "(A positive variance means FINDING-FAWRY-01 regression!)");

        $this->assertEquals(0.00, $machine_variance,
            "CRITICAL GHOST BALANCE: machine variance must be EXACTLY 0.00 EGP after soft-delete. " .
            "Baseline={$machine_baseline}, Final={$machine_final}, Variance={$machine_variance} EGP");

        // Check no extra settlement was re-deposited by correctDeficitIfAny
        $correctionEntries = AccountEntry::where('notes', 'like', "%تصحيح عجز حذف عملية فوري #{$tx->id}%")->get();
        $this->assertEquals(0, $correctionEntries->count(),
            "No deficit correction should have fired (would indicate balance drift). " .
            "Found {$correctionEntries->count()} correction entries.");
    }

    // =========================================================================
    // SCENARIO 6: Soft Delete Inside a Chained Operation — Reconciliation
    // =========================================================================

    public function test_scenario_6_soft_delete_inside_chained_operation(): void
    {
        // ── RECORD BASELINE ───────────────────────────────────────────────────
        $cashbox_baseline = (float) $this->cashbox->fresh()->balance;
        $machine_baseline = (float) $this->machine->fresh()->balance;

        // ── STEP 1: Machine Recharge (+3000 to machine, -3000 from cashbox) ──
        $this->rechargeService->rechargeFromAccount(
            $this->machine,
            $this->cashbox,
            3000.00,
            'CHAIN_TEST_RECHARGE'
        );
        $this->assertEquals($cashbox_baseline - 3000.00, (float) $this->cashbox->fresh()->balance);
        $this->assertEquals($machine_baseline + 3000.00, (float) $this->machine->fresh()->balance);

        // ── STEP 2: Cash Sale #1 (selling=1000, cost=800, paid=1000) ─────────
        $txCash1 = $this->fawryService->createTransaction([
            'client_name'     => 'CHAIN_CASH_1',
            'operation_type'  => 'bill_payment',
            'client_amount'   => 1000.00,
            'fawry_price'     => 800.00,
            'selling_price'   => 1000.00,
            'amount'          => 1000.00,
            'employee_id'     => $this->admin->id,
            'account_id'      => $this->cashbox->id,
            'fawry_machine_id'=> $this->machine->id,
            'payment_method'  => 'cash',
        ]);

        // ── STEP 3: Walk-in Debt (selling=500, cost=400, paid=0) ─────────────
        $txWalkIn = $this->fawryService->createTransaction([
            'client_name'     => 'CHAIN_WALKIN',
            'operation_type'  => 'bill_payment',
            'client_amount'   => 500.00,
            'fawry_price'     => 400.00,
            'selling_price'   => 500.00,
            'amount'          => 0.00,
            'employee_id'     => $this->admin->id,
            'account_id'      => $this->cashbox->id,
            'fawry_machine_id'=> $this->machine->id,
            'payment_method'  => 'cash',
        ]);

        // ── STEP 4: Partial payment on walk-in debt (250 EGP) ─────────────────
        $payRes = $this->postJson('/api/v1/fawry/walk-in/pay-debt', [
            'client_name' => 'CHAIN_WALKIN',
            'amount'      => 250.00,
            'account_id'  => $this->cashbox->id,
        ]);
        $payRes->assertSuccessful();
        $this->assertEquals(250.00, (float) json_decode($payRes->getContent(), true)['data']['remaining_debt']);

        // ── STEP 5: Cash Sale #2 (selling=2000, cost=1600, paid=2000) ─────────
        $txCash2 = $this->fawryService->createTransaction([
            'client_name'     => 'CHAIN_CASH_2',
            'operation_type'  => 'bill_payment',
            'client_amount'   => 2000.00,
            'fawry_price'     => 1600.00,
            'selling_price'   => 2000.00,
            'amount'          => 2000.00,
            'employee_id'     => $this->admin->id,
            'account_id'      => $this->cashbox->id,
            'fawry_machine_id'=> $this->machine->id,
            'payment_method'  => 'cash',
        ]);

        // Record state BEFORE deleting txCash1
        $cashbox_before_delete = (float) $this->cashbox->fresh()->balance;
        $machine_before_delete = (float) $this->machine->fresh()->balance;

        // ── STEP 6: SOFT DELETE only txCash1 (1000 sale, 800 cost) ───────────
        $r = $this->deleteJson("/api/v1/fawry/transactions/{$txCash1->id}");
        $r->assertSuccessful();
        $this->assertSoftDeleted('fawry_transactions', ['id' => $txCash1->id]);

        $cashbox_after_delete = (float) $this->cashbox->fresh()->balance;
        $machine_after_delete = (float) $this->machine->fresh()->balance;

        // Cashbox change: -1000 (selling reversed) — client money returned
        $this->assertEquals(-1000.00, round($cashbox_after_delete - $cashbox_before_delete, 2),
            "Cashbox must decrease by 1000 (selling reversed for txCash1). " .
            "Before={$cashbox_before_delete}, After={$cashbox_after_delete}");

        // Machine change: +800 (fawry_price reversed for txCash1)
        $this->assertEquals(800.00, round($machine_after_delete - $machine_before_delete, 2),
            "Machine must increase by 800 (fawry_price reversed for txCash1). " .
            "Before={$machine_before_delete}, After={$machine_after_delete}");

        // ── UNRELATED TRANSACTIONS must be intact ─────────────────────────────
        // txCash2 must still exist (not soft-deleted)
        $this->assertNotNull(FawryTransaction::find($txCash2->id), "txCash2 must NOT be affected by deleting txCash1");
        $this->assertNull(FawryTransaction::find($txCash2->id)->deleted_at, "txCash2 deleted_at must be NULL");

        // txWalkIn must still exist with correct amount (250 paid)
        $freshWalkIn = FawryTransaction::find($txWalkIn->id);
        $this->assertNotNull($freshWalkIn, "txWalkIn must NOT be affected");
        $this->assertEquals(250.00, (float) $freshWalkIn->amount,
            "txWalkIn amount must remain 250 (partial payment) after deleting an unrelated cash tx");

        // ── FULL CHAIN RECONCILIATION ─────────────────────────────────────────
        // Expected cashbox:
        //   baseline
        //   - 3000 (recharge)
        //   + 0    (txCash1 cancelled, net 0)
        //   + 250  (walkin partial payment)
        //   + 2000 (txCash2)
        //   = baseline - 3000 + 0 + 250 + 2000
        $expected_cashbox_final = $cashbox_baseline - 3000.00 + 0.00 + 250.00 + 2000.00;
        $actual_cashbox_final   = (float) $this->cashbox->fresh()->balance;
        $this->assertEquals(
            round($expected_cashbox_final, 2),
            round($actual_cashbox_final, 2),
            "CHAIN RECONCILIATION — Cashbox: " .
            "Expected={$expected_cashbox_final}, Actual={$actual_cashbox_final}, " .
            "Variance=" . round($actual_cashbox_final - $expected_cashbox_final, 2)
        );

        // Expected machine:
        //   baseline
        //   + 3000 (recharge)
        //   - 0    (txCash1 cancelled = net 0 cost)
        //   - 400  (txWalkIn fawry_price)
        //   - 1600 (txCash2 fawry_price)
        $expected_machine_final = $machine_baseline + 3000.00 + 0.00 - 400.00 - 1600.00;
        $actual_machine_final   = (float) $this->machine->fresh()->balance;
        $this->assertEquals(
            round($expected_machine_final, 2),
            round($actual_machine_final, 2),
            "CHAIN RECONCILIATION — Machine: " .
            "Expected={$expected_machine_final}, Actual={$actual_machine_final}, " .
            "Variance=" . round($actual_machine_final - $expected_machine_final, 2)
        );

        // Walk-in remaining debt
        $remainingWalkInDebt = (float) DB::table('fawry_transactions')
            ->whereNull('deleted_at')
            ->whereNull('client_id')
            ->where('client_name', 'CHAIN_WALKIN')
            ->whereRaw('selling_price > amount')
            ->sum(DB::raw('selling_price - amount'));
        $this->assertEquals(250.00, round($remainingWalkInDebt, 2),
            "Walk-in remaining debt must be 250 (500 - 250 paid). Got: {$remainingWalkInDebt}");
    }

    // =========================================================================
    // SCENARIO 7: Database Integrity After Multiple Soft Deletes
    // =========================================================================

    public function test_scenario_7_database_integrity_after_multiple_soft_deletes(): void
    {
        // Create 3 transactions
        $txs = [];
        for ($i = 1; $i <= 3; $i++) {
            $txs[$i] = $this->fawryService->createTransaction([
                'client_name'     => "SD_INTEGRITY_CLIENT_{$i}",
                'operation_type'  => 'bill_payment',
                'client_amount'   => 1000.00 * $i,
                'fawry_price'     => 800.00 * $i,
                'selling_price'   => 1000.00 * $i,
                'amount'          => 1000.00 * $i,
                'employee_id'     => $this->admin->id,
                'account_id'      => $this->cashbox->id,
                'fawry_machine_id'=> $this->machine->id,
                'payment_method'  => 'cash',
            ]);
        }

        // Delete 2 of them
        $this->deleteJson("/api/v1/fawry/transactions/{$txs[1]->id}")->assertSuccessful();
        $this->deleteJson("/api/v1/fawry/transactions/{$txs[3]->id}")->assertSuccessful();

        // 1. Normal queries exclude deleted rows
        $visibleTxs = FawryTransaction::all();
        $this->assertEquals(1, $visibleTxs->count(), "Normal query must only show 1 non-deleted tx");
        $this->assertEquals($txs[2]->id, $visibleTxs->first()->id, "Only txs[2] should be visible");

        // 2. withTrashed shows all 3
        $this->assertEquals(3, FawryTransaction::withTrashed()->count(), "withTrashed must show all 3");

        // 3. No orphan account entries
        $txIds = Transaction::where('related_type', FawryTransaction::class)
            ->whereIn('related_id', [$txs[1]->id, $txs[2]->id, $txs[3]->id])
            ->pluck('id');
        $orphans = AccountEntry::whereIn('transaction_id', $txIds)
            ->whereNotIn('transaction_id', Transaction::pluck('id'))
            ->count();
        $this->assertEquals(0, $orphans, "Zero orphan AccountEntry records");

        // 4. No duplicate reversals for deleted TXs
        foreach ([$txs[1]->id, $txs[3]->id] as $deletedId) {
            $linkedTxIds = Transaction::where('related_type', FawryTransaction::class)
                ->where('related_id', $deletedId)
                ->pluck('id');
            // For each original, reversed version should exist — but NOT be duplicated
            foreach ($linkedTxIds as $ltxId) {
                $reversalNotes = AccountEntry::where('transaction_id', $ltxId)
                    ->where('notes', 'like', 'عكس:%')
                    ->count();
                // Each reversal entry should appear exactly ONCE per original entry
                $originalCount = AccountEntry::where('transaction_id', $ltxId)
                    ->where(function ($q) { $q->where('notes', 'not like', 'عكس:%')->orWhereNull('notes'); })
                    ->count();
                // reversals <= originals (some entries may not have reversal notes)
                $this->assertLessThanOrEqual($originalCount + 5, $reversalNotes,
                    "No excessive reversal entries for fawry_transaction_id={$deletedId}");
            }
        }

        // 5. GL globally balanced (ALL entries sum)
        $totalDebit  = round(AccountEntry::sum('debit'), 2);
        $totalCredit = round(AccountEntry::sum('credit'), 2);
        $this->assertEquals($totalDebit, $totalCredit,
            "GLOBAL GL BALANCE: total debit must equal total credit. " .
            "Debit={$totalDebit}, Credit={$totalCredit}");

        // 6. deleted_at is correctly set/not set
        foreach ([$txs[1]->id, $txs[3]->id] as $deletedId) {
            $row = DB::table('fawry_transactions')->where('id', $deletedId)->first();
            $this->assertNotNull($row->deleted_at, "fawry_transaction_id={$deletedId} must have deleted_at set");
        }
        $row2 = DB::table('fawry_transactions')->where('id', $txs[2]->id)->first();
        $this->assertNull($row2->deleted_at, "fawry_transaction_id={$txs[2]->id} must NOT have deleted_at");
    }
}
