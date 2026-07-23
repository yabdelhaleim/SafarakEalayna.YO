<?php

namespace Tests\Feature\Fawry;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\Fawry\FawryTransaction;
use App\Models\User;
use App\Services\Fawry\FawryTransactionService;
use App\Services\Finance\LedgerClearingAccounts;
use App\Services\Finance\TransactionService;
use App\Services\Reports\FinancialReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Validates the walk-in Fawry debt flow:
 *  - creation routes the receivable through the unified AR account
 *  - repayment endpoint applies FIFO to fawry_transactions.amount
 *  - the debt surfaces in `getDebtsReport` (office department)
 *  - overpayments / unknown clients are rejected
 *  - legacy rows (client_id IS NULL, no AR entries) still surface in the report
 */
class WalkInFawryPaymentTest extends TestCase
{
    use RefreshDatabase;

    protected FawryTransactionService $service;

    protected User $user;

    protected Account $settlementAccount;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(FawryTransactionService::class);
        $this->user = User::factory()->create();
        $this->settlementAccount = Account::factory()->active()->create([
            'name' => 'Cashbox EGP',
            'type' => AccountType::Cashbox,
            'currency' => 'EGP',
            'balance' => 10000,
            'module_type' => 'office',
        ]);

        Auth::login($this->user);
    }

    /**
     * Authenticate the test request as the seeded user via Sanctum.
     * Mirrors the helper used in FawryTransactionControllerTest.
     */
    public function actingAs($user, $driver = null)
    {
        \Laravel\Sanctum\Sanctum::actingAs($user, ['*']);

        return $this;
    }

    public function test_walk_in_ar_account_is_created_lazy(): void
    {
        $clearing = app(LedgerClearingAccounts::class);

        $firstId = $clearing->fawryWalkInArAccountId();
        $this->assertIsInt($firstId);
        $this->assertGreaterThan(0, $firstId);

        $secondId = $clearing->fawryWalkInArAccountId();
        $this->assertSame($firstId, $secondId, 'second call should return the same account id');

        $account = Account::find($firstId);
        $this->assertSame('ذمم عملاء فوري غير مسجلين', $account->name);
        $this->assertSame(AccountType::Customer, $account->type);
        $this->assertSame('fawry', $account->module_type);
        $this->assertFalse((bool) $account->is_module_vault);
        $this->assertSame(Account::OWNER_TYPE_OWNER, $account->owner_type);
    }

    public function test_walk_in_ajal_transaction_credits_ar_account(): void
    {
        $this->service->createTransaction([
            'client_name' => 'عماد طه',
            'operation_type' => 'bill_payment',
            'client_amount' => 44.0,
            'fawry_price' => 40.0,
            'selling_price' => 44.0,
            'employee_id' => $this->user->id,
            'payment_method' => 'cash',
            'amount' => 0.0,
            'account_id' => $this->settlementAccount->id,
        ]);

        $arId = app(LedgerClearingAccounts::class)->fawryWalkInArAccountId();
        $ar = Account::find($arId);
        $this->assertSame(44.0, (float) $ar->balance);
    }

    public function test_walk_in_partial_payment_clears_only_paid_amount(): void
    {
        $this->service->createTransaction([
            'client_name' => 'حلمي الأحمر',
            'operation_type' => 'withdrawal',
            'client_amount' => 290.0,
            'fawry_price' => 270.0,
            'selling_price' => 290.0,
            'employee_id' => $this->user->id,
            'payment_method' => 'cash',
            'amount' => 50.0,
            'account_id' => $this->settlementAccount->id,
        ]);

        $arId = app(LedgerClearingAccounts::class)->fawryWalkInArAccountId();
        $this->assertSame(240.0, (float) Account::find($arId)->balance);

        $tx = FawryTransaction::where('client_name', 'حلمي الأحمر')->firstOrFail();
        $this->assertSame(50.0, (float) $tx->amount);
    }

    public function test_walk_in_pay_debt_endpoint_applies_fifo(): void
    {
        // Create 3 walk-in transactions for the same client (different amounts)
        $tx1 = $this->service->createTransaction([
            'client_name' => 'أحمد سيد',
            'operation_type' => 'bill_payment',
            'client_amount' => 100.0, 'fawry_price' => 90.0, 'selling_price' => 100.0,
            'employee_id' => $this->user->id, 'payment_method' => 'cash',
            'amount' => 0.0, 'account_id' => $this->settlementAccount->id,
        ]);
        $tx2 = $this->service->createTransaction([
            'client_name' => 'أحمد سيد',
            'operation_type' => 'bill_payment',
            'client_amount' => 200.0, 'fawry_price' => 180.0, 'selling_price' => 200.0,
            'employee_id' => $this->user->id, 'payment_method' => 'cash',
            'amount' => 0.0, 'account_id' => $this->settlementAccount->id,
        ]);
        $tx3 = $this->service->createTransaction([
            'client_name' => 'أحمد سيد',
            'operation_type' => 'bill_payment',
            'client_amount' => 300.0, 'fawry_price' => 270.0, 'selling_price' => 300.0,
            'employee_id' => $this->user->id, 'payment_method' => 'cash',
            'amount' => 0.0, 'account_id' => $this->settlementAccount->id,
        ]);

        // Pay 250 EGP — FIFO should fill tx1 (100), tx2 (150), leave tx3 untouched
        $response = $this->actingAs($this->user)->postJson('/api/v1/fawry/walk-in/pay-debt', [
            'client_name' => 'أحمد سيد',
            'amount' => 250.0,
            'account_id' => $this->settlementAccount->id,
            'notes' => 'partial FIFO test',
        ]);

        $response->assertSuccessful();
        $response->assertJsonPath('data.fully_settled', false);
        $this->assertEquals(350.0, (float) $response->json('data.remaining_debt'));

        $tx1->refresh();
        $tx2->refresh();
        $tx3->refresh();
        $this->assertSame(100.0, (float) $tx1->amount, 'tx1 fully paid');
        $this->assertSame(150.0, (float) $tx2->amount, 'tx2 partially paid');
        $this->assertSame(0.0, (float) $tx3->amount, 'tx3 untouched');
    }

    public function test_walk_in_pay_debt_fully_settles(): void
    {
        $this->service->createTransaction([
            'client_name' => 'سامي كامل',
            'operation_type' => 'bill_payment',
            'client_amount' => 100.0, 'fawry_price' => 95.0, 'selling_price' => 100.0,
            'employee_id' => $this->user->id, 'payment_method' => 'cash',
            'amount' => 0.0, 'account_id' => $this->settlementAccount->id,
        ]);

        $response = $this->actingAs($this->user)->postJson('/api/v1/fawry/walk-in/pay-debt', [
            'client_name' => 'سامي كامل',
            'amount' => 100.0,
            'account_id' => $this->settlementAccount->id,
        ]);

        $response->assertSuccessful();
        $response->assertJsonPath('data.fully_settled', true);
        $this->assertEquals(0.0, (float) $response->json('data.remaining_debt'));
    }

    public function test_walk_in_pay_debt_rejects_overpayment(): void
    {
        $this->service->createTransaction([
            'client_name' => 'محمد علي',
            'operation_type' => 'bill_payment',
            'client_amount' => 100.0, 'fawry_price' => 90.0, 'selling_price' => 100.0,
            'employee_id' => $this->user->id, 'payment_method' => 'cash',
            'amount' => 0.0, 'account_id' => $this->settlementAccount->id,
        ]);

        $response = $this->actingAs($this->user)->postJson('/api/v1/fawry/walk-in/pay-debt', [
            'client_name' => 'محمد علي',
            'amount' => 150.0,
            'account_id' => $this->settlementAccount->id,
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('يتجاوز المديونية الفعلية', $response->json('message'));
    }

    public function test_walk_in_pay_debt_rejects_unknown_client(): void
    {
        $response = $this->actingAs($this->user)->postJson('/api/v1/fawry/walk-in/pay-debt', [
            'client_name' => 'شخص غير موجود',
            'amount' => 50.0,
            'account_id' => $this->settlementAccount->id,
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('لا توجد مديونية', $response->json('message'));
    }

    public function test_walk_in_pay_debt_rejects_foreign_currency_settlement(): void
    {
        $usdAccount = Account::factory()->active()->create([
            'name' => 'USD Bank',
            'type' => AccountType::Bank,
            'currency' => 'USD',
            'balance' => 10000,
            'module_type' => 'office',
        ]);

        $this->service->createTransaction([
            'client_name' => 'عميل USD',
            'operation_type' => 'bill_payment',
            'client_amount' => 100.0, 'fawry_price' => 90.0, 'selling_price' => 100.0,
            'employee_id' => $this->user->id, 'payment_method' => 'cash',
            'amount' => 0.0, 'account_id' => $this->settlementAccount->id,
        ]);

        $response = $this->actingAs($this->user)->postJson('/api/v1/fawry/walk-in/pay-debt', [
            'client_name' => 'عميل USD',
            'amount' => 50.0,
            'account_id' => $usdAccount->id,
        ]);

        $response->assertStatus(422);
    }

    public function test_walk_in_debt_appears_in_get_debts_report_office(): void
    {
        $this->service->createTransaction([
            'client_name' => 'عماد طه',
            'operation_type' => 'bill_payment',
            'client_amount' => 44.0, 'fawry_price' => 40.0, 'selling_price' => 44.0,
            'employee_id' => $this->user->id, 'payment_method' => 'cash',
            'amount' => 0.0, 'account_id' => $this->settlementAccount->id,
        ]);

        $this->service->createTransaction([
            'client_name' => 'حلمي الأحمر',
            'operation_type' => 'withdrawal',
            'client_amount' => 290.0, 'fawry_price' => 270.0, 'selling_price' => 290.0,
            'employee_id' => $this->user->id, 'payment_method' => 'cash',
            'amount' => 0.0, 'account_id' => $this->settlementAccount->id,
        ]);

        $report = app(FinancialReportService::class)->getDebtsReport([
            'department' => 'office',
            'direction' => 'all',
            'entity_type' => 'all',
        ]);

        $walkIn = array_values(array_filter(
            $report['items'],
            fn ($i) => $i['entity_type'] === 'walkin_fawry'
        ));

        $this->assertCount(2, $walkIn, 'two walk-in rows should surface');

        $byName = [];
        foreach ($walkIn as $row) {
            $byName[$row['name']] = $row;
        }

        $this->assertArrayHasKey('عماد طه', $byName);
        $this->assertArrayHasKey('حلمي الأحمر', $byName);
        $this->assertSame(44.0, (float) $byName['عماد طه']['balance']);
        $this->assertSame(290.0, (float) $byName['حلمي الأحمر']['balance']);
        $this->assertTrue($byName['عماد طه']['walk_in']);
        $this->assertSame('office', $byName['عماد طه']['department']);
        $this->assertSame('fawry', $byName['عماد طه']['module']);

        // Total receivables should reflect the walk-in debt
        $this->assertSame(334.0, (float) $report['total_receivables']);
    }

    public function test_legacy_walk_in_transaction_without_gl_entries_still_surfaces(): void
    {
        // Simulate a legacy walk-in transaction: pre-fix flow would have
        //   credited the settlement account directly with no AR entries.
        // We model that by inserting a row directly (no service call).
        $settlement = $this->settlementAccount;

        DB::table('fawry_transactions')->insert([
            'client_id' => null,
            'client_name' => 'legacy walk-in',
            'operation_type' => 'bill_payment',
            'client_amount' => 100.0,
            'fawry_price' => 90.0,
            'selling_price' => 100.0,
            'profit' => 10.0,
            'employee_id' => $this->user->id,
            'account_id' => $settlement->id,
            'payment_method' => 'cash',
            'amount' => 0.0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $report = app(FinancialReportService::class)->getDebtsReport([
            'department' => 'office',
            'direction' => 'all',
            'entity_type' => 'all',
        ]);

        $walkIn = array_values(array_filter(
            $report['items'],
            fn ($i) => $i['entity_type'] === 'walkin_fawry'
        ));

        $this->assertCount(1, $walkIn);
        $this->assertSame('legacy walk-in', $walkIn[0]['name']);
        $this->assertSame(100.0, (float) $walkIn[0]['balance']);
    }

    public function test_walk_in_debt_filtered_out_when_entity_type_is_customer(): void
    {
        $this->service->createTransaction([
            'client_name' => 'walkin only',
            'operation_type' => 'bill_payment',
            'client_amount' => 50.0, 'fawry_price' => 45.0, 'selling_price' => 50.0,
            'employee_id' => $this->user->id, 'payment_method' => 'cash',
            'amount' => 0.0, 'account_id' => $this->settlementAccount->id,
        ]);

        $report = app(FinancialReportService::class)->getDebtsReport([
            'department' => 'office',
            'direction' => 'all',
            'entity_type' => 'customer',
        ]);

        $this->assertEmpty(array_filter($report['items'], fn ($i) => $i['entity_type'] === 'walkin_fawry'));
    }

    public function test_walk_in_debt_filtered_out_when_entity_type_is_walkin_fawry_only(): void
    {
        $this->service->createTransaction([
            'client_name' => 'walkin only',
            'operation_type' => 'bill_payment',
            'client_amount' => 50.0, 'fawry_price' => 45.0, 'selling_price' => 50.0,
            'employee_id' => $this->user->id, 'payment_method' => 'cash',
            'amount' => 0.0, 'account_id' => $this->settlementAccount->id,
        ]);

        $report = app(FinancialReportService::class)->getDebtsReport([
            'department' => 'office',
            'direction' => 'all',
            'entity_type' => 'walkin_fawry',
        ]);

        $this->assertNotEmpty(array_filter($report['items'], fn ($i) => $i['entity_type'] === 'walkin_fawry'));
    }

    public function test_walk_in_payment_decreases_ar_account_balance(): void
    {
        $this->service->createTransaction([
            'client_name' => 'test walk-in',
            'operation_type' => 'bill_payment',
            'client_amount' => 200.0, 'fawry_price' => 180.0, 'selling_price' => 200.0,
            'employee_id' => $this->user->id, 'payment_method' => 'cash',
            'amount' => 0.0, 'account_id' => $this->settlementAccount->id,
        ]);

        $arId = app(LedgerClearingAccounts::class)->fawryWalkInArAccountId();
        $this->assertSame(200.0, (float) Account::find($arId)->balance);

        $this->actingAs($this->user)->postJson('/api/v1/fawry/walk-in/pay-debt', [
            'client_name' => 'test walk-in',
            'amount' => 75.0,
            'account_id' => $this->settlementAccount->id,
        ])->assertSuccessful();

        $this->assertSame(125.0, (float) Account::find($arId)->fresh()->balance);
    }
}
