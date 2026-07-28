<?php

namespace Tests\Feature\Fawry;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\Fawry\FawryTransaction;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Fawry\FawryTransactionService;
use App\Services\Reports\ProfitLossReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Regression for the finance/accounts deficit alert that surfaces a
 * soft-deleted Fawry operation:
 *
 *  - After the operator cancels a Fawry operation the
 *    `finance/accounts` dashboard must NOT show the operation in
 *    `recent_transactions` and must NOT show the settlement account
 *    in `deficit_accounts` unless there is still an outstanding
 *    deficit from a non-soft-deleted source.
 *  - The P&L `performance` breakdown used by the dashboard must also
 *    exclude revenue/expense rows linked to the soft-deleted operation.
 */
class FawryDeleteExcludesFromAccountsDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected FawryTransactionService $service;

    protected User $user;

    protected Account $settlementAccount;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(FawryTransactionService::class);
        $this->user = User::factory()->create([
            'role' => 'admin',
        ]);
        $this->settlementAccount = Account::factory()->active()->create([
            'name' => 'نقدي دينار',
            'type' => AccountType::Cashbox,
            'currency' => 'EGP',
            'balance' => 5000,
            'module_type' => 'office',
        ]);

        Auth::login($this->user);
        Sanctum::actingAs($this->user, ['*']);
    }

    public function test_soft_deleted_fawry_op_disappears_from_finance_accounts_recent_activity(): void
    {
        $tx = $this->service->createTransaction([
            'client_name' => 'محمد محذوف',
            'operation_type' => 'bill_payment',
            'client_amount' => 500.00,
            'fawry_price' => 480.00,
            'selling_price' => 500.00,
            'employee_id' => $this->user->id,
            'payment_method' => 'cash',
            'amount' => 500.00,
            'account_id' => $this->settlementAccount->id,
            'notes' => 'للحذف',
        ]);

        $linkedOriginal = Transaction::query()
            ->where('related_type', FawryTransaction::class)
            ->where('related_id', $tx->id)
            ->get();
        $this->assertGreaterThan(0, $linkedOriginal->count());

        // Sanity: the operation shows up in recent_transactions BEFORE delete
        $before = $this->getJson('/api/v1/finance/accounts?per_page=100');
        $before->assertOk();
        $beforeRecentIds = collect($before->json('data.stats.recent_transactions'))->pluck('id')->all();
        $this->assertContains($tx->income_transaction_id, $beforeRecentIds);

        $this->service->deleteTransaction($tx);

        // After deletion: the soft-deleted Fawry operation's linked
        // transactions are excluded from recent_transactions.
        $after = $this->getJson('/api/v1/finance/accounts?per_page=100');
        $after->assertOk();
        $afterRecentIds = collect($after->json('data.stats.recent_transactions'))->pluck('id')->all();
        $this->assertNotContains($tx->income_transaction_id, $afterRecentIds);
        $this->assertNotContains($tx->expense_transaction_id, $afterRecentIds);
    }

    public function test_soft_deleted_fawry_op_does_not_leave_deficit_alert(): void
    {
        $tx = $this->service->createTransaction([
            'client_name' => 'عمر',
            'operation_type' => 'bill_payment',
            'client_amount' => 300.00,
            'fawry_price' => 290.00,
            'selling_price' => 300.00,
            'employee_id' => $this->user->id,
            'payment_method' => 'cash',
            'amount' => 300.00,
            'account_id' => $this->settlementAccount->id,
        ]);

        $this->service->deleteTransaction($tx);

        $response = $this->getJson('/api/v1/finance/accounts?per_page=100');
        $response->assertOk();

        $deficitIds = collect($response->json('data.stats.deficit_accounts'))->pluck('id')->all();
        $this->assertNotContains(
            $this->settlementAccount->id,
            $deficitIds,
            'A deleted Fawry operation must not leave a phantom deficit in the finance dashboard.'
        );
    }

    public function test_soft_deleted_fawry_op_is_excluded_from_profit_loss_module_breakdown(): void
    {
        $tx = $this->service->createTransaction([
            'client_name' => 'محمود',
            'operation_type' => 'bill_payment',
            'client_amount' => 200.00,
            'fawry_price' => 195.00,
            'selling_price' => 200.00,
            'employee_id' => $this->user->id,
            'payment_method' => 'cash',
            'amount' => 200.00,
            'account_id' => $this->settlementAccount->id,
        ]);

        // Before delete: fawry module shows non-zero income
        $plService = app(ProfitLossReportService::class);
        $before = collect($plService->moduleBreakdown()['by_module']);
        $fawryBefore = $before->firstWhere('module', 'fawry');
        $this->assertNotNull($fawryBefore, 'fawry module should appear in breakdown before delete');
        $this->assertGreaterThan(0, (float) ($fawryBefore['income'] ?? 0));

        $this->service->deleteTransaction($tx);

        $after = collect($plService->moduleBreakdown()['by_module']);
        $fawryAfter = $after->firstWhere('module', 'fawry');

        if ($fawryAfter !== null) {
            $this->assertSame(
                0.0,
                (float) ($fawryAfter['income'] ?? 0),
                'After soft-deleting the only Fawry op, moduleBreakdown income must be 0.'
            );
        }
    }

    public function test_double_delete_is_idempotent(): void
    {
        $tx = $this->service->createTransaction([
            'client_name' => 'أحمد',
            'operation_type' => 'bill_payment',
            'client_amount' => 100.00,
            'fawry_price' => 95.00,
            'selling_price' => 100.00,
            'employee_id' => $this->user->id,
            'payment_method' => 'cash',
            'amount' => 100.00,
            'account_id' => $this->settlementAccount->id,
        ]);

        $this->service->deleteTransaction($tx);
        // Second call must be a safe no-op (Bug D idempotency guard).
        $this->service->deleteTransaction($tx->fresh());
        $this->assertSoftDeleted('fawry_transactions', ['id' => $tx->id]);
    }
}
