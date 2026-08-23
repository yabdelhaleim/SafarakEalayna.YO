<?php

namespace Tests\Feature\Fawry;

use App\Enums\AccountType;
use App\Enums\TransactionModule;
use App\Models\Account;
use App\Models\Customer;
use App\Models\Fawry\FawryMachine;
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
 * Validates that the Fawry dashboard stats endpoint returns a consistent
 * and complete picture, especially under the walk-in pay-debt flow which
 * mutates `amount` on existing rows (without creating a new transaction).
 *
 * Reproduces the production bug where `total_payments` showed 0 even
 * after a 200 EGP cash payment was made.
 *
 * @see \App\Http\Controllers\Api\V1\Fawry\FawryDashboardController
 */
class FawryDashboardStatsConsistencyTest extends TestCase
{
    use RefreshDatabase;

    protected FawryTransactionService $service;

    protected User $user;

    protected Account $settlementAccount;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(FawryTransactionService::class);
        $this->user = User::factory()->create(['role' => 'admin']);
        $this->settlementAccount = Account::factory()->active()->create([
            'name' => 'Cashbox EGP',
            'type' => AccountType::Cashbox,
            'currency' => 'EGP',
            'balance' => 10000,
            // Liquidity accounts must be tagged with a DIVISION, not the
            // module itself (see App\Support\Finance\AccountModuleContract).
            'module_type' => 'office',
            'module' => 'fawry',
        ]);

        Auth::login($this->user);
        Sanctum::actingAs($this->user, ['*']);
    }

    private function hitDashboard(): array
    {
        $response = $this->getJson('/api/v1/fawry/dashboard');
        $response->assertOk();
        $response->assertJsonStructure(['data' => ['stats' => [], 'recent_transactions' => []]]);

        return $response->json('data.stats');
    }

    // ================================================================
    // Section 1 — Cash payment at creation appears in total_payments
    // ================================================================
    public function test_cash_payment_at_creation_is_reflected_in_total_payments(): void
    {
        // A fully-paid-at-creation cash withdrawal (reproduces the production
        // screenshot row: محمد, سحب, 200, 200, مدفوع بالكامل).
        $this->service->createTransaction([
            'client_name' => 'محمد',
            'operation_type' => 'withdrawal',
            'client_amount' => 200.0,
            'fawry_price' => 195.0,
            'selling_price' => 200.0,
            'employee_id' => $this->user->id,
            'payment_method' => 'cash',
            'amount' => 200.0,
            'account_id' => $this->settlementAccount->id,
        ]);

        $stats = $this->hitDashboard();

        // === Bug regression ===
        // Old production bug: total_payments was 0 even though a 200 EGP
        // cash payment was made. The fix sources total_payments from
        // SUM(amount) WHERE amount > 0 AND deleted_at IS NULL, which
        // includes any row with a positive `amount`.
        $this->assertSame(200.0, (float) $stats['total_payments'], 'total_payments must include fully-paid-at-creation cash transactions');
        $this->assertSame(200.0, (float) $stats['total_bills'], 'total_bills must equal SUM(selling_price)');
        $this->assertSame(1, (int) $stats['total_transactions'], 'exactly 1 transaction should be counted');
        $this->assertSame(0, (int) $stats['pending_transactions'], 'fully paid transactions should not be pending');
        $this->assertSame(0, (int) $stats['due_transactions'], 'fully paid transactions should not be due');
        $this->assertSame(0, (int) $stats['incomplete_transactions'], 'transactions with amount=0 should be incomplete');
        $this->assertSame(0.0, (float) $stats['total_dues'], 'fully paid transactions should have 0 total dues');
    }

    public function test_partial_payment_reflects_correctly_in_due_and_payment_stats(): void
    {
        // A 200 transaction with 50 paid, 150 outstanding.
        $this->service->createTransaction([
            'client_name' => 'سامي',
            'operation_type' => 'bill_payment',
            'client_amount' => 200.0,
            'fawry_price' => 0.0,
            'selling_price' => 200.0,
            'employee_id' => $this->user->id,
            'payment_method' => 'cash',
            'amount' => 50.0,
            'account_id' => $this->settlementAccount->id,
        ]);

        $stats = $this->hitDashboard();

        $this->assertSame(200.0, (float) $stats['total_bills']);
        $this->assertSame(50.0, (float) $stats['total_payments']);
        $this->assertSame(150.0, (float) $stats['total_dues']);
        $this->assertSame(1, (int) $stats['pending_transactions'], 'partial payment row is pending');
        $this->assertSame(1, (int) $stats['due_transactions'], 'partial payment row is due');
        $this->assertSame(0, (int) $stats['incomplete_transactions'], 'partial payment has amount > 0');
    }

    public function test_unpaid_transaction_counts_as_incomplete_with_full_due(): void
    {
        // آجل بالكامل (amount = 0)
        $this->service->createTransaction([
            'client_name' => 'هاني',
            'operation_type' => 'bill_payment',
            'client_amount' => 300.0,
            'fawry_price' => 0.0,
            'selling_price' => 300.0,
            'employee_id' => $this->user->id,
            'payment_method' => 'cash',
            'amount' => 0.0,
            'account_id' => $this->settlementAccount->id,
        ]);

        $stats = $this->hitDashboard();

        $this->assertSame(300.0, (float) $stats['total_bills']);
        $this->assertSame(0.0, (float) $stats['total_payments']);
        $this->assertSame(300.0, (float) $stats['total_dues']);
        $this->assertSame(1, (int) $stats['incomplete_transactions']);
        $this->assertSame(1, (int) $stats['pending_transactions']);
        $this->assertSame(1, (int) $stats['due_transactions']);
    }

    // ================================================================
    // Section 2 — Walk-in pay-debt: amount column updated, total_payments reflects
    // ================================================================
    public function test_walk_in_pay_debt_updates_total_payments_after_allocation(): void
    {
        // 1) Create an unpaid walk-in bill (amount = 0)
        $this->service->createTransaction([
            'client_name' => 'طارق',
            'operation_type' => 'bill_payment',
            'client_amount' => 500.0,
            'fawry_price' => 0.0,
            'selling_price' => 500.0,
            'employee_id' => $this->user->id,
            'payment_method' => 'cash',
            'amount' => 0.0,
            'account_id' => $this->settlementAccount->id,
        ]);

        // Pre-condition: total_payments should be 0 because the bill is unpaid.
        $statsBefore = $this->hitDashboard();
        $this->assertSame(0.0, (float) $statsBefore['total_payments']);
        $this->assertSame(500.0, (float) $statsBefore['total_dues']);
        $this->assertSame(1, (int) $statsBefore['incomplete_transactions']);

        // 2) Pay 200 of the 500 debt via the walk-in payment endpoint
        $payResponse = $this->postJson('/api/v1/fawry/walk-in/pay-debt', [
            'client_name' => 'طارق',
            'amount' => 200.0,
            'account_id' => $this->settlementAccount->id,
            'notes' => 'تسديد جزئي',
        ]);
        $payResponse->assertOk();
        $payResponse->assertJsonPath('data.fully_settled', false);
        $this->assertEquals(300.0, (float) $payResponse->json('data.remaining_debt'));

        // 3) Re-fetch dashboard — total_payments must now reflect the 200
        $statsAfter = $this->hitDashboard();
        $this->assertSame(200.0, (float) $statsAfter['total_payments'], 'walk-in pay-debt MUST increment total_payments (production bug regression)');
        $this->assertSame(500.0, (float) $statsAfter['total_bills']);
        $this->assertSame(300.0, (float) $statsAfter['total_dues'], 'total_dues must reflect 500 - 200 paid');
        $this->assertSame(1, (int) $statsAfter['pending_transactions'], 'still partial → still pending');
        $this->assertSame(0, (int) $statsAfter['incomplete_transactions'], 'amount > 0 → no longer incomplete');
        $this->assertSame(1, (int) $statsAfter['total_transactions'], 'pay-debt does NOT create a new row — still 1');
    }

    public function test_walk_in_pay_debt_full_settlement_clears_dues(): void
    {
        $this->service->createTransaction([
            'client_name' => 'كريم',
            'operation_type' => 'bill_payment',
            'client_amount' => 100.0,
            'fawry_price' => 0.0,
            'selling_price' => 100.0,
            'employee_id' => $this->user->id,
            'payment_method' => 'cash',
            'amount' => 0.0,
            'account_id' => $this->settlementAccount->id,
        ]);

        $payResponse = $this->postJson('/api/v1/fawry/walk-in/pay-debt', [
            'client_name' => 'كريم',
            'amount' => 100.0,
            'account_id' => $this->settlementAccount->id,
        ]);
        $payResponse->assertOk();
        $payResponse->assertJsonPath('data.fully_settled', true);
        $this->assertEquals(0.0, (float) $payResponse->json('data.remaining_debt'));

        $stats = $this->hitDashboard();

        $this->assertSame(100.0, (float) $stats['total_payments']);
        $this->assertSame(0.0, (float) $stats['total_dues']);
        $this->assertSame(0, (int) $stats['pending_transactions']);
        $this->assertSame(0, (int) $stats['due_transactions']);
        $this->assertSame(0, (int) $stats['incomplete_transactions']);
    }

    // ================================================================
    // Section 3 — Soft-deleted transactions are excluded everywhere
    // ================================================================
    public function test_soft_deleted_transactions_are_excluded_from_all_stats(): void
    {
        // 1) Create a 200 cash transaction
        $tx = $this->service->createTransaction([
            'client_name' => 'محمود',
            'operation_type' => 'withdrawal',
            'client_amount' => 200.0,
            'fawry_price' => 195.0,
            'selling_price' => 200.0,
            'employee_id' => $this->user->id,
            'payment_method' => 'cash',
            'amount' => 200.0,
            'account_id' => $this->settlementAccount->id,
        ]);

        $statsBefore = $this->hitDashboard();
        $this->assertSame(1, (int) $statsBefore['total_transactions']);
        $this->assertSame(200.0, (float) $statsBefore['total_payments']);

        // 2) Soft-delete the transaction
        $fawryTx = FawryTransaction::find($tx->id);
        $fawryTx->delete();

        $statsAfter = $this->hitDashboard();
        $this->assertSame(0, (int) $statsAfter['total_transactions'], 'soft-deleted rows must NOT count');
        $this->assertSame(0.0, (float) $statsAfter['total_payments'], 'soft-deleted amount must NOT inflate payments');
        $this->assertSame(0.0, (float) $statsAfter['total_bills'], 'soft-deleted selling_price must NOT inflate bills');
        $this->assertSame(0.0, (float) $statsAfter['total_dues']);
        $this->assertSame(0, (int) $statsAfter['pending_transactions']);
        $this->assertSame(0, (int) $statsAfter['due_transactions']);
    }

    // ================================================================
    // Section 4 — Invariant: due_transactions > 0 ⇒ total_dues > 0
    // ================================================================
    public function test_due_count_and_total_dues_are_consistent(): void
    {
        // Two partial rows + one full-paid row
        $this->service->createTransaction([
            'client_name' => 'A',
            'operation_type' => 'bill_payment',
            'client_amount' => 100.0, 'fawry_price' => 0.0, 'selling_price' => 100.0,
            'employee_id' => $this->user->id, 'payment_method' => 'cash',
            'amount' => 30.0, 'account_id' => $this->settlementAccount->id,
        ]);
        $this->service->createTransaction([
            'client_name' => 'B',
            'operation_type' => 'bill_payment',
            'client_amount' => 200.0, 'fawry_price' => 0.0, 'selling_price' => 200.0,
            'employee_id' => $this->user->id, 'payment_method' => 'cash',
            'amount' => 50.0, 'account_id' => $this->settlementAccount->id,
        ]);
        $this->service->createTransaction([
            'client_name' => 'C',
            'operation_type' => 'bill_payment',
            'client_amount' => 80.0, 'fawry_price' => 0.0, 'selling_price' => 80.0,
            'employee_id' => $this->user->id, 'payment_method' => 'cash',
            'amount' => 80.0, 'account_id' => $this->settlementAccount->id,
        ]);

        $stats = $this->hitDashboard();

        // === Invariant check ===
        // Production bug: total_dues=1 but due_transactions=0 (or vice versa).
        $this->assertSame(2, (int) $stats['due_transactions']);
        $this->assertGreaterThan(0.005, (float) $stats['total_dues'], 'due_transactions>0 ⇒ total_dues>0');
        $this->assertSame(220.0, (float) $stats['total_dues'], 'total_dues = 70 + 150 = 220');
        $this->assertSame(2, (int) $stats['pending_transactions']);
        $this->assertSame(0, (int) $stats['incomplete_transactions']);
        $this->assertSame(160.0, (float) $stats['total_payments'], 'total_payments = 30 + 50 + 80 = 160');
        $this->assertSame(380.0, (float) $stats['total_bills']);
    }

    public function test_empty_database_returns_zero_for_all_stats(): void
    {
        $stats = $this->hitDashboard();

        $this->assertSame(0, (int) $stats['total_transactions']);
        $this->assertSame(0, (int) $stats['pending_transactions']);
        $this->assertSame(0, (int) $stats['due_transactions']);
        $this->assertSame(0, (int) $stats['incomplete_transactions']);
        $this->assertSame(0.0, (float) $stats['total_bills']);
        $this->assertSame(0.0, (float) $stats['total_payments']);
        $this->assertSame(0.0, (float) $stats['total_dues']);
        $this->assertSame(0.0, (float) $stats['monthly_revenue']);
    }

    // ================================================================
    // Section 5 — Time-windowed stats (today / month)
    // ================================================================
    public function test_today_and_monthly_revenue_exclude_old_transactions(): void
    {
        // Insert a transaction from 2 months ago (must be older than the
        // current month so it is provably excluded from `monthly_revenue`).
        // Using `subDays(2)` was wrong: on day 3 of any month, "yesterday"
        // is still inside the current month so `monthly_revenue` SHOULD
        // include it — the previous test asserted the opposite and failed
        // whenever the system clock was after day 1 of the month.
        DB::table('fawry_transactions')->insert([
            'client_name' => 'قديم',
            'operation_type' => 'withdrawal',
            'client_amount' => 100.0,
            'fawry_price' => 95.0,
            'selling_price' => 100.0,
            'profit' => 5.0,
            'employee_id' => $this->user->id,
            'account_id' => $this->settlementAccount->id,
            'payment_method' => 'cash',
            'amount' => 100.0,
            'created_at' => now()->subMonths(2),
            'updated_at' => now()->subMonths(2),
        ]);

        // And one from "yesterday" to verify today_revenue ALSO excludes it
        // even though it IS in the current month.
        DB::table('fawry_transactions')->insert([
            'client_name' => 'أمس',
            'operation_type' => 'withdrawal',
            'client_amount' => 25.0,
            'fawry_price' => 23.0,
            'selling_price' => 25.0,
            'profit' => 2.0,
            'employee_id' => $this->user->id,
            'account_id' => $this->settlementAccount->id,
            'payment_method' => 'cash',
            'amount' => 25.0,
            'created_at' => now()->subDay(),
            'updated_at' => now()->subDay(),
        ]);

        $this->service->createTransaction([
            'client_name' => 'جديد',
            'operation_type' => 'withdrawal',
            'client_amount' => 50.0,
            'fawry_price' => 48.0,
            'selling_price' => 50.0,
            'employee_id' => $this->user->id,
            'payment_method' => 'cash',
            'amount' => 50.0,
            'account_id' => $this->settlementAccount->id,
        ]);

        $stats = $this->hitDashboard();

        $this->assertSame(175.0, (float) $stats['total_bills'], 'total_bills includes all-time transactions (100 + 25 + 50)');
        $this->assertSame(175.0, (float) $stats['total_payments']);
        $this->assertSame(50.0, (float) $stats['today_revenue'], 'today_revenue excludes yesterday and 2-months-ago transactions');
        $this->assertSame(75.0, (float) $stats['monthly_revenue'], 'monthly_revenue excludes 2-months-ago but INCLUDES yesterday (50 + 25)');
        $this->assertSame(1, (int) $stats['today_transactions']);
        $this->assertSame(3, (int) $stats['total_transactions']);
    }

    // ================================================================
    // Section 6 — registered customer debt vs walk-in debt split
    // ================================================================
    public function test_registered_customer_debt_is_separate_from_walkin_debt(): void
    {
        // Walk-in آجل
        $this->service->createTransaction([
            'client_name' => 'عميل_غير_مسجل',
            'operation_type' => 'bill_payment',
            'client_amount' => 400.0, 'fawry_price' => 0.0, 'selling_price' => 400.0,
            'employee_id' => $this->user->id, 'payment_method' => 'cash',
            'amount' => 0.0, 'account_id' => $this->settlementAccount->id,
        ]);

        $stats = $this->hitDashboard();

        $this->assertSame(400.0, (float) $stats['walkin_debt']);
        $this->assertSame(1, (int) $stats['walkin_clients_count']);
        $this->assertSame(0.0, (float) $stats['customers_debt'], 'no registered customer transaction yet');
    }

    // ================================================================
    // Section 7 — walk-in AR account is excluded from customers_debt
    // (Phase C regression — it has module_type='fawry' like a real
    // customer account, so without explicit filtering it doubled up).
    // ================================================================
    public function test_walkin_ar_account_does_not_inflate_customers_debt(): void
    {
        // Walk-in آجل 400 EGP — this routes to the unified walk-in AR account
        // ("ذمم عملاء فوري غير مسجلين") which is itself a Customer-type
        // account with module_type='fawry'. Before the Phase C fix the
        // dashboard would (a) count 400 under customers_debt AND (b) count
        // 400 under walkin_debt — double-counting.
        $this->service->createTransaction([
            'client_name' => 'walkin_only',
            'operation_type' => 'bill_payment',
            'client_amount' => 400.0, 'fawry_price' => 0.0, 'selling_price' => 400.0,
            'employee_id' => $this->user->id, 'payment_method' => 'cash',
            'amount' => 0.0, 'account_id' => $this->settlementAccount->id,
        ]);

        // Also create a registered-customer transaction so customers_debt
        // would be > 0 if the walk-in AR isn't excluded.
        $customer = Customer::create([
            'full_name' => 'عميل_مسجل',
            'phone' => '01000000099',
            'created_by' => $this->user->id,
        ]);
        $this->service->createTransaction([
            'client_id' => $customer->id,
            'operation_type' => 'bill_payment',
            'client_amount' => 250.0, 'fawry_price' => 200.0, 'selling_price' => 250.0,
            'employee_id' => $this->user->id, 'payment_method' => 'cash',
            'amount' => 100.0, // 150 still owed
            'account_id' => $this->settlementAccount->id,
        ]);

        $stats = $this->hitDashboard();

        // Walk-in debt reflects the 400 walk-in (correct)
        $this->assertSame(400.0, (float) $stats['walkin_debt'], 'walkin_debt must reflect walk-in 400');

        // Customers debt reflects ONLY the registered customer (150 outstanding)
        // NOT walk-in 400. Before Phase C fix this would be 400 + 150 = 550.
        $this->assertSame(150.0, (float) $stats['customers_debt'], 'customers_debt must exclude walk-in AR — should be 150, not 550');
    }

    // ================================================================
    // Section 8 — recent_transactions excludes soft-deleted rows
    // (Phase B regression — soft-delete reverses the GL entries so the
    // row is dead but would still appear in the table and confuse operators).
    // ================================================================
    public function test_soft_deleted_transactions_excluded_from_recent_transactions(): void
    {
        // Create 2 transactions, then soft-delete one
        $alive = $this->service->createTransaction([
            'client_name' => 'حي',
            'operation_type' => 'withdrawal',
            'client_amount' => 100.0, 'fawry_price' => 95.0, 'selling_price' => 100.0,
            'employee_id' => $this->user->id, 'payment_method' => 'cash',
            'amount' => 100.0, 'account_id' => $this->settlementAccount->id,
        ]);

        $dead = $this->service->createTransaction([
            'client_name' => 'ميت',
            'operation_type' => 'withdrawal',
            'client_amount' => 200.0, 'fawry_price' => 190.0, 'selling_price' => 200.0,
            'employee_id' => $this->user->id, 'payment_method' => 'cash',
            'amount' => 200.0, 'account_id' => $this->settlementAccount->id,
        ]);

        // Soft-delete the second transaction
        FawryTransaction::find($dead->id)->delete();

        $response = $this->getJson('/api/v1/fawry/dashboard');
        $response->assertOk();

        $recent = $response->json('data.recent_transactions');
        $recentIds = array_column($recent, 'id');

        $this->assertContains($alive->id, $recentIds, 'alive transaction must appear in recent_transactions');
        $this->assertNotContains($dead->id, $recentIds, 'soft-deleted transaction must NOT appear in recent_transactions');
    }

    // ================================================================
    // Section 9 — liquidity stats include office-division vaults
    // (Phase-7 regression — production seeder creates cashboxes with
    // module_type='office'; the previous strict equality filter excluded
    // them and surfaced as الرصيد الفعلي = 0 on the dashboard).
    // ================================================================
    public function test_liquidity_stats_include_office_division_vaults(): void
    {
        // The setUp() creates a settlement account with
        // `module='fawry'` + `module_type='office'`. The dashboard's
        // liquidity filter MUST include it (via applyModuleFilter's
        // division-aware expansion). Before the Phase-7 fix this
        // account would be excluded → cashboxes.balance = 0 → الرصيد
        // الفعلي = 0 on the dashboard.
        $response = $this->getJson('/api/v1/fawry/dashboard');
        $stats = $response->json('data.stats');

        // The settlement account has balance 10,000 EGP. If it appears
        // in the cashboxes bucket, that bucket should be 10,000 (or
        // more, if other active cashboxes exist in the test DB).
        $this->assertGreaterThanOrEqual(
            10000.0,
            (float) $stats['cashboxes']['balance'],
            'cashboxes.balance must include office-division Fawry settlement account (10,000 EGP)'
        );
        $this->assertGreaterThanOrEqual(
            1,
            (int) $stats['cashboxes']['count'],
            'cashboxes.count must include the office-division Fawry settlement account'
        );
    }
}
