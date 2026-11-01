<?php

namespace Tests\Feature\Finance;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\AccountEntry;
use App\Models\AuditLog;
use App\Models\Transaction;
use App\Models\Transfer;
use App\Models\User;
use App\Services\Finance\TransactionService;
use App\Services\Reports\ReportFinanceService;
use App\Support\Finance\LedgerBalanceMutationGuard;
use App\Support\UserPermissions;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ExpenseManagementTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;
    private User $employeeNoPerm;
    private User $financeEmployee;
    private Account $treasuryA;
    private Account $treasuryB;
    private Account $expenseAccount;
    private TransactionService $txService;

    protected function setUp(): void
    {
        parent::setUp();

        // Print active database to satisfy safety rule:
        $activeDb = DB::connection()->getDatabaseName();
        echo "\n[TEST ENV] Running on DB: {$activeDb}\n";
        $this->assertStringContainsString('test', $activeDb, 'CRITICAL: Tests must run on a test database!');

        $this->txService = app(TransactionService::class);

        // 1. Admin User
        $this->admin = User::firstOrCreate(
            ['email' => 'admin_test_expense@safarak.local'],
            [
                'name' => 'Admin Tester',
                'password' => Hash::make('password'),
                'role' => 'admin',
                'is_active' => true,
            ]
        );

        // 2. Employee with NO finance permission
        $this->employeeNoPerm = User::firstOrCreate(
            ['email' => 'emp_no_perm@safarak.local'],
            [
                'name' => 'Employee No Perm',
                'password' => Hash::make('password'),
                'role' => 'employee',
                'permissions' => [],
                'is_active' => true,
            ]
        );

        // 3. Employee WITH manage_finance permission
        $this->financeEmployee = User::firstOrCreate(
            ['email' => 'finance_emp@safarak.local'],
            [
                'name' => 'Finance Employee',
                'password' => Hash::make('password'),
                'role' => 'employee',
                'permissions' => [UserPermissions::MANAGE_FINANCE],
                'is_active' => true,
            ]
        );

        // Accounts setup
        $this->treasuryA = LedgerBalanceMutationGuard::run(function () {
            return Account::firstOrCreate(
                ['name' => 'TEST_خزينة_رئيسية_أ'],
                [
                    'type' => AccountType::Cashbox->value,
                    'currency' => 'EGP',
                    'balance' => 5000.00,
                    'is_active' => true,
                    'owner_type' => Account::OWNER_TYPE_OFFICE,
                    'module_type' => 'office',
                    'created_by' => $this->admin->id,
                ]
            );
        });
        // Ensure clean starting balance
        LedgerBalanceMutationGuard::run(function () {
            $this->treasuryA->update(['balance' => 5000.00]);
        });

        $this->treasuryB = LedgerBalanceMutationGuard::run(function () {
            return Account::firstOrCreate(
                ['name' => 'TEST_خزينة_فرعية_ب'],
                [
                    'type' => AccountType::Cashbox->value,
                    'currency' => 'EGP',
                    'balance' => 3000.00,
                    'is_active' => true,
                    'owner_type' => Account::OWNER_TYPE_OFFICE,
                    'module_type' => 'office',
                    'created_by' => $this->admin->id,
                ]
            );
        });
        LedgerBalanceMutationGuard::run(function () {
            $this->treasuryB->update(['balance' => 3000.00]);
        });

        $this->expenseAccount = LedgerBalanceMutationGuard::run(function () {
            return Account::firstOrCreate(
                ['name' => 'TEST_بند_كهرباء'],
                [
                    'type' => AccountType::Expense->value,
                    'currency' => 'EGP',
                    'balance' => 0.00,
                    'is_active' => true,
                    'owner_type' => Account::OWNER_TYPE_OFFICE,
                    'module_type' => 'general',
                    'created_by' => $this->admin->id,
                ]
            );
        });
        LedgerBalanceMutationGuard::run(function () {
            $this->expenseAccount->update(['balance' => 0.00]);
        });
    }

    /**
     * Helper to assert Invariant on an account:
     * balance == SUM(credit) - SUM(debit)
     */
    private function assertAccountInvariant(Account $account, string $message = ''): void
    {
        $account->refresh();
        $calc = AccountEntry::query()
            ->where('account_id', $account->id)
            ->selectRaw('COALESCE(SUM(credit) - SUM(debit), 0) as calc_balance')
            ->value('calc_balance');

        $this->assertEquals(
            round((float) $calc, 2),
            round((float) $account->balance, 2),
            "Invariant violated for account {$account->name} ({$account->id}): Stored={$account->balance}, Calculated={$calc}. {$message}"
        );
    }

    /**
     * Test 1: Delete expense restores treasury balance, soft-deletes records, and writes AuditLog.
     */
    public function test_delete_expense_restores_balance_soft_deletes_and_audits(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        // Record a 1000 EGP expense from Treasury A
        $transfer = $this->txService->recordTransfer([
            'from_account_id' => $this->treasuryA->id,
            'to_account_id' => $this->expenseAccount->id,
            'amount' => 1000.00,
            'type' => 'expense',
            'notes' => 'شحن كهرباء تجريبي',
            'created_by' => $this->admin->id,
        ]);

        $tx = Transaction::findOrFail($transfer->transaction_id);
        $this->treasuryA->refresh();
        $this->assertEquals(4000.00, (float) $this->treasuryA->balance);
        $this->assertAccountInvariant($this->treasuryA, 'After expense creation');

        // Execute DELETE API
        $response = $this->deleteJson("/api/v1/finance/transactions/{$tx->id}");
        $response->assertOk();
        $response->assertJsonPath('success', true);

        // 1. Balance returned to Treasury A (4000 + 1000 = 5000)
        $this->treasuryA->refresh();
        $this->assertEquals(5000.00, (float) $this->treasuryA->balance);
        $this->assertAccountInvariant($this->treasuryA, 'After expense deletion');

        // 2. Soft-deleted: not found in normal query, exists in withTrashed
        $this->assertNull(Transaction::find($tx->id));
        $this->assertNotNull(Transaction::withTrashed()->find($tx->id));
        $this->assertNotNull(Transfer::withTrashed()->find($transfer->id));

        // 3. AuditLog written
        $audit = AuditLog::where('action', 'expense.deleted')
            ->where('model_id', $tx->id)
            ->first();
        $this->assertNotNull($audit);
        $this->assertEquals($this->admin->id, $audit->user_id);
    }

    /**
     * Test 2: Update amount (1000 -> 100): exact 900 returned to treasury.
     */
    public function test_update_amount_adjusts_treasury_balance_accurately(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        // Record a 1000 EGP expense
        $transfer = $this->txService->recordTransfer([
            'from_account_id' => $this->treasuryA->id,
            'to_account_id' => $this->expenseAccount->id,
            'amount' => 1000.00,
            'type' => 'expense',
            'notes' => 'شحن كهرباء 1000ج بالخطأ',
            'created_by' => $this->admin->id,
        ]);

        $tx = Transaction::findOrFail($transfer->transaction_id);
        $this->treasuryA->refresh();
        $this->assertEquals(4000.00, (float) $this->treasuryA->balance);

        // Update amount to 100 EGP
        $response = $this->putJson("/api/v1/finance/transactions/{$tx->id}", [
            'amount' => 100.00,
            'account_id' => $this->treasuryA->id,
            'to_account_id' => $this->expenseAccount->id,
            'notes' => 'تصحيح شحن كهرباء إلى 100ج',
        ]);

        $response->assertOk();

        // Treasury balance must now be 5000 - 100 = 4900 (+900 restored!)
        $this->treasuryA->refresh();
        $this->assertEquals(4900.00, (float) $this->treasuryA->balance);
        $this->assertAccountInvariant($this->treasuryA, 'After amount update');

        // Audit log exists
        $audit = AuditLog::where('action', 'expense.updated')->first();
        $this->assertNotNull($audit);
        $this->assertEquals(1000.00, $audit->old_values['amount']);
        $this->assertEquals(100.00, $audit->new_values['amount']);
    }

    /**
     * Test 3: Changing treasury (move expense from Treasury A to Treasury B).
     */
    public function test_change_treasury_reverses_old_and_charges_new(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $transfer = $this->txService->recordTransfer([
            'from_account_id' => $this->treasuryA->id,
            'to_account_id' => $this->expenseAccount->id,
            'amount' => 500.00,
            'type' => 'expense',
            'notes' => 'مصروف من خزينة أ',
            'created_by' => $this->admin->id,
        ]);

        $tx = Transaction::findOrFail($transfer->transaction_id);
        $this->treasuryA->refresh();
        $this->assertEquals(4500.00, (float) $this->treasuryA->balance);
        $this->assertEquals(3000.00, (float) $this->treasuryB->balance);

        // Update: move to Treasury B
        $response = $this->putJson("/api/v1/finance/transactions/{$tx->id}", [
            'amount' => 500.00,
            'account_id' => $this->treasuryB->id,
            'to_account_id' => $this->expenseAccount->id,
            'notes' => 'نقل المصروف لخزينة ب',
        ]);

        $response->assertOk();

        // Treasury A restored to 5000.00, Treasury B deducted to 2500.00
        $this->treasuryA->refresh();
        $this->treasuryB->refresh();
        $this->assertEquals(5000.00, (float) $this->treasuryA->balance);
        $this->assertEquals(2500.00, (float) $this->treasuryB->balance);

        $this->assertAccountInvariant($this->treasuryA, 'Treasury A after move');
        $this->assertAccountInvariant($this->treasuryB, 'Treasury B after move');
    }

    /**
     * Test 4: Unauthorized user is rejected with 403.
     */
    public function test_unauthorized_user_cannot_delete_or_update(): void
    {
        $transfer = $this->txService->recordTransfer([
            'from_account_id' => $this->treasuryA->id,
            'to_account_id' => $this->expenseAccount->id,
            'amount' => 300.00,
            'type' => 'expense',
            'notes' => 'مصروف عادي',
            'created_by' => $this->admin->id,
        ]);

        $tx = Transaction::findOrFail($transfer->transaction_id);

        // Attempt as Employee without permission
        Sanctum::actingAs($this->employeeNoPerm, ['*']);
        $this->deleteJson("/api/v1/finance/transactions/{$tx->id}")->assertStatus(403);
        $this->putJson("/api/v1/finance/transactions/{$tx->id}", [
            'amount' => 200.00,
            'account_id' => $this->treasuryA->id,
        ])->assertStatus(403);

        // Attempt as Employee WITH manage_finance permission -> ALLOWED
        Sanctum::actingAs($this->financeEmployee, ['*']);
        $this->deleteJson("/api/v1/finance/transactions/{$tx->id}")->assertOk();
    }

    /**
     * Test 5: Rejection of non-operating expense (e.g. flight/bus booking).
     */
    public function test_non_manual_expense_is_rejected(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        // Create transaction linked to an external booking (related_type NOT NULL)
        $tx = Transaction::create([
            'type' => 'expense',
            'amount' => 500.00,
            'currency' => 'EGP',
            'module' => 'flight',
            'from_account_id' => $this->treasuryA->id,
            'to_account_id' => $this->expenseAccount->id,
            'related_type' => 'App\Models\FlightBooking',
            'related_id' => 999,
            'created_by' => $this->admin->id,
        ]);

        // Attempt to delete or update -> REJECTED (422)
        $this->deleteJson("/api/v1/finance/transactions/{$tx->id}")->assertStatus(422);
        $this->putJson("/api/v1/finance/transactions/{$tx->id}", [
            'amount' => 400.00,
            'account_id' => $this->treasuryA->id,
        ])->assertStatus(422);
    }

    /**
     * Test 6: Closed drawer behavior.
     * Regular employee rejected if drawer closed after transaction date.
     * Admin permitted to correct.
     */
    public function test_closed_drawer_rejects_regular_and_allows_admin(): void
    {
        // 1. Expense created on 2026-05-01
        $transfer = $this->txService->recordTransfer([
            'from_account_id' => $this->treasuryA->id,
            'to_account_id' => $this->expenseAccount->id,
            'amount' => 250.00,
            'type' => 'expense',
            'created_by' => $this->financeEmployee->id,
        ]);

        $tx = Transaction::findOrFail($transfer->transaction_id);
        $tx->forceFill(['created_at' => '2026-05-01 10:00:00'])->save();

        // 2. Drawer closed on 2026-05-01 18:00:00
        AuditLog::create([
            'user_id' => $this->admin->id,
            'action' => 'close_drawer',
            'model_type' => Account::class,
            'model_id' => $this->treasuryA->id,
            'created_at' => '2026-05-01 18:00:00',
        ]);

        // 3. Finance Employee (non-admin) tries to delete -> REJECTED 422
        Sanctum::actingAs($this->financeEmployee, ['*']);
        $resp = $this->deleteJson("/api/v1/finance/transactions/{$tx->id}");
        $resp->assertStatus(422);
        $this->assertStringContainsString('الخزينة مغلقة', $resp->json('message'));

        // 4. Admin tries to delete -> ALLOWED with audit override
        Sanctum::actingAs($this->admin, ['*']);
        $respAdmin = $this->deleteJson("/api/v1/finance/transactions/{$tx->id}");
        $respAdmin->assertOk();

        $audit = AuditLog::where('action', 'expense.deleted')->where('model_id', $tx->id)->first();
        $this->assertTrue($audit->new_values['is_closed_drawer_override']);
    }

    /**
     * Test 7: Idempotency & double delete protection.
     */
    public function test_double_delete_is_prevented(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $transfer = $this->txService->recordTransfer([
            'from_account_id' => $this->treasuryA->id,
            'to_account_id' => $this->expenseAccount->id,
            'amount' => 400.00,
            'type' => 'expense',
            'created_by' => $this->admin->id,
        ]);

        $tx = Transaction::findOrFail($transfer->transaction_id);

        // First delete -> OK
        $this->deleteJson("/api/v1/finance/transactions/{$tx->id}")->assertOk();

        // Second delete -> 404 (already deleted/soft-deleted)
        $this->deleteJson("/api/v1/finance/transactions/{$tx->id}")->assertStatus(404);

        // Treasury only refunded once
        $this->treasuryA->refresh();
        $this->assertEquals(5000.00, (float) $this->treasuryA->balance);
    }

    /**
     * Test 8: Validation rejects invalid amounts (zero, negative, non-numeric).
     */
    public function test_validation_rejects_invalid_inputs(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $transfer = $this->txService->recordTransfer([
            'from_account_id' => $this->treasuryA->id,
            'to_account_id' => $this->expenseAccount->id,
            'amount' => 150.00,
            'type' => 'expense',
            'created_by' => $this->admin->id,
        ]);

        $tx = Transaction::findOrFail($transfer->transaction_id);

        $this->putJson("/api/v1/finance/transactions/{$tx->id}", [
            'amount' => -50,
            'account_id' => $this->treasuryA->id,
        ])->assertStatus(422);

        $this->putJson("/api/v1/finance/transactions/{$tx->id}", [
            'amount' => 0,
            'account_id' => $this->treasuryA->id,
        ])->assertStatus(422);

        $this->putJson("/api/v1/finance/transactions/{$tx->id}", [
            'amount' => 'invalid_text',
            'account_id' => $this->treasuryA->id,
        ])->assertStatus(422);
    }

    /**
     * Test 9: Reports and financial listings exclude soft-deleted expenses.
     */
    public function test_reports_exclude_deleted_expenses(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $transfer = $this->txService->recordTransfer([
            'from_account_id' => $this->treasuryA->id,
            'to_account_id' => $this->expenseAccount->id,
            'amount' => 777.00,
            'type' => 'expense',
            'notes' => 'مصروف للفحص في التقارير 777',
            'created_by' => $this->admin->id,
        ]);

        $tx = Transaction::findOrFail($transfer->transaction_id);

        // Before delete: visible in report
        $reportService = app(ReportFinanceService::class);
        $paginatorBefore = $reportService->getTransactionReport(['expenses_only' => 1, 'search' => '777']);
        $this->assertCount(1, $paginatorBefore->items());

        // Delete expense
        $this->deleteJson("/api/v1/finance/transactions/{$tx->id}")->assertOk();

        // After delete: NOT visible in report
        $paginatorAfter = $reportService->getTransactionReport(['expenses_only' => 1, 'search' => '777']);
        $this->assertCount(0, $paginatorAfter->items());
    }
}
