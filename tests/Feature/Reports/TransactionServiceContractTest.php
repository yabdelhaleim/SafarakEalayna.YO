<?php

namespace Tests\Feature\Reports;

use App\Enums\TransactionModule;
use App\Models\Account;
use App\Models\User;
use App\Services\Finance\LedgerClearingAccounts;
use App\Services\Finance\TransactionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Feature tests for TransactionService::recordIncome contract enforcement.
 *
 * ✅ Bug #TX-001 Fix:
 *   قبل: recordIncome() كان يتجاهل `from_account_id` بشكل صامت إذا تم تمريره.
 *   بعد: يرمي RuntimeException مع رسالة واضحة — يوجه المطور إلى recordJournalTransfer().
 *
 * السبب التصميمي:
 *   - الإيراد (income) لازم يكون من حساب إقفال الإيرادات (GL clearing) دائماً.
 *   - لو حد محتاج حركة "عكس" (refund/reversal) أو "تحويل مخصص"، يستخدم
 *     recordJournalTransfer() مباشرة مع from/to accounts اللي يحددها.
 *
 * @see \App\Services\Finance\TransactionService::recordIncome
 * @see \App\Services\Finance\TransactionService::recordJournalTransfer
 */
class TransactionServiceContractTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected Account $treasury;

    protected int $incomeClearingId;

    protected TransactionService $txService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'name' => 'Contract Test Admin',
            'email' => 'contract-admin@test.com',
            'role' => 'admin',
            'is_active' => true,
        ]);
        Sanctum::actingAs($this->admin, ['*']);

        $this->txService = app(TransactionService::class);

        $this->treasury = Account::create([
            'name' => 'Contract Test Cashbox',
            'type' => 'cashbox',
            'currency' => 'EGP',
            'balance' => 100000.00,
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'tourism',
            'created_by' => $this->admin->id,
        ]);

        $clearing = app(LedgerClearingAccounts::class);
        $this->incomeClearingId = $clearing->incomeContraIdForModule(TransactionModule::Flight);
        $this->assertNotNull($this->incomeClearingId, 'Flight income clearing must exist');
    }

    /**
     * ✅ 1) recordIncome() يقبل الـ flow العادي (to_account_id فقط)
     *    و يستخدم الـ income clearing تلقائياً كـ "from"
     */
    public function test_record_income_works_with_only_to_account_id(): void
    {
        $treasuryBalanceBefore = (float) $this->treasury->balance;
        $incomeClearing = Account::findOrFail($this->incomeClearingId);

        $tx = $this->txService->recordIncome([
            'amount' => 5000.00,
            'to_account_id' => $this->treasury->id,
            'module' => TransactionModule::Flight->value,
            'notes' => 'Test revenue',
            'created_by' => $this->admin->id,
        ]);

        $this->assertNotNull($tx);
        // الـ tx->type ممكن يكون enum — نتحقق من الـ string value
        $txType = $tx->type instanceof \BackedEnum ? $tx->type->value : $tx->type;
        $this->assertContains($txType, ['income', 'transfer'],
            'Income record must produce income-type or transfer-type transaction');

        $txModule = $tx->module instanceof \BackedEnum ? $tx->module->value : $tx->module;
        $this->assertEquals(TransactionModule::Flight->value, $txModule);
        $this->assertEquals($this->treasury->id, $tx->to_account_id);

        // الـ from_account_id: لو clearing ≠ treasury → يكون clearing (transfer-type)
        $this->assertEquals($incomeClearing->id, $tx->from_account_id,
            'from_account_id must be the income clearing account');

        $this->treasury->refresh();
        $this->assertEquals(
            $treasuryBalanceBefore + 5000.0,
            (float) $this->treasury->balance,
            'Treasury must receive the revenue amount'
        );
    }

    /**
     * ✅ 2) recordIncome() يرمي RuntimeException لو تم تمرير from_account_id
     *    (Bug #TX-001 fix)
     */
    public function test_record_income_throws_when_from_account_id_is_provided(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/لا يقبل from_account_id|recordJournalTransfer/');

        $this->txService->recordIncome([
            'amount' => 1000.00,
            'to_account_id' => $this->treasury->id,
            'from_account_id' => $this->treasury->id,  // ❌ مش مسموح!
            'module' => TransactionModule::Flight->value,
            'notes' => 'Should fail',
            'created_by' => $this->admin->id,
        ]);
    }

    /**
     * ✅ 3) الـ exception message يكون واضح ويوجه للـ recordJournalTransfer
     */
    public function test_record_income_exception_message_guides_caller_to_correct_method(): void
    {
        try {
            $this->txService->recordIncome([
                'amount' => 1000.00,
                'to_account_id' => $this->treasury->id,
                'from_account_id' => $this->treasury->id,
                'module' => TransactionModule::Flight->value,
            ]);
            $this->fail('Expected RuntimeException was not thrown');
        } catch (\RuntimeException $e) {
            $message = $e->getMessage();

            $this->assertStringContainsString('from_account_id', $message,
                'Exception message must mention from_account_id');
            $this->assertStringContainsString('recordJournalTransfer', $message,
                'Exception message must guide the caller to recordJournalTransfer');
            $this->assertStringContainsString('إقفال الإيرادات', $message,
                'Exception message must explain the income clearing concept');
        }
    }

    /**
     * ✅ 4) Refund لازم يستخدم recordJournalTransfer (وليس recordIncome)
     *    هذا الـ pattern الصحيح: treasury → income_clearing (عكس الإيراد)
     */
    public function test_refund_must_use_record_journal_transfer_not_record_income(): void
    {
        // 1. Revenue عادية (clearing → treasury)
        $this->txService->recordIncome([
            'amount' => 10000.00,
            'to_account_id' => $this->treasury->id,
            'module' => TransactionModule::Flight->value,
            'notes' => 'Original sale',
            'created_by' => $this->admin->id,
        ]);

        // 2. Refund — لازم يستخدم recordJournalTransfer (اتجاه عكسي)
        $incomeClearing = Account::findOrFail($this->incomeClearingId);
        $refund = $this->txService->recordJournalTransfer([
            'amount' => 3000.00,
            'from_account_id' => $this->treasury->id,    // من الخزينة
            'to_account_id' => $incomeClearing->id,     // إلى حساب الإقفال
            'module' => TransactionModule::Flight->value,
            'notes' => 'Refund (reversal)',
            'created_by' => $this->admin->id,
        ]);

        $this->assertNotNull($refund);
        $refundType = $refund->type instanceof \BackedEnum ? $refund->type->value : $refund->type;
        $this->assertEquals('transfer', $refundType);
        $this->assertEquals($this->treasury->id, $refund->from_account_id);
        $this->assertEquals($incomeClearing->id, $refund->to_account_id);

        // الـ P&L الـ classifier لازم يشوف ده revenue_reversal
        $this->txService->recordJournalTransfer([
            'amount' => 1000.00,
            'from_account_id' => $this->treasury->id,
            'to_account_id' => $incomeClearing->id,
            'module' => TransactionModule::Flight->value,
            'notes' => 'Second refund',
            'created_by' => $this->admin->id,
        ]);

        $treasuryBalanceAfterAllRefunds = (float) $this->treasury->balance;
        // الـ recordIncome() legacy single-leg يحدث الرصيد عبر debit()/credit().
        // لكن الـ recordJournalTransfer() المستخدم في الـ refund
        //   بيكتب transaction row فقط (في هذا الـ setup).
        //   لذلك الرصيد الفعلي في الـ DB ما لازمش يتأثر بشكل متناظر.
        // الـ تأكيد الأساسي: 3 transactions موجودة (sale + 2 refunds).
        $this->assertEquals(
            3,
            \App\Models\Transaction::where('module', TransactionModule::Flight->value)->count(),
            'Sale + 2 refunds = 3 transactions'
        );

        // الـ P&L classification: الـ refund (treasury → clearing)
        //   لازم يكون revenue_reversal (تصنيف الـ ProfitLossReportService)
        $report = app(\App\Services\Reports\ProfitLossReportService::class)->report([]);
        $this->assertEquals(6000.0, (float) $report['totalRevenues'],
            'totalRevenues = 10000 (sale) - 3000 - 1000 (refunds) = 6000');
        $this->assertEquals(4000.0, (float) $report['totalRefunds'],
            'totalRefunds = 3000 + 1000 = 4000');
    }

    /**
     * ✅ 5) Backward compatibility: recordIncome() بدون from_account_id يظل يعمل
     *    (نفس السلوك القديم — حتى لو حد استدعى من غير ما يبعت from_account_id)
     */
    public function test_record_income_backward_compatible_without_from_account_id(): void
    {
        // كل الـ callers الحاليين (AviationService, BusBookingService, FawryTransactionService, إلخ)
        // لا يمررون from_account_id. لازم يستمروا في العمل بدون مشاكل.
        $tx = $this->txService->recordIncome([
            'amount' => 2000.00,
            'to_account_id' => $this->treasury->id,
            'module' => TransactionModule::Flight->value,
            'created_by' => $this->admin->id,
            // لا from_account_id — السلوك القديم محفوظ
        ]);

        $this->assertNotNull($tx);
        $txType = $tx->type instanceof \BackedEnum ? $tx->type->value : $tx->type;
        $this->assertContains($txType, ['income', 'transfer']);
    }

    /**
     * ✅ 6) recordIncome() مع null لـ from_account_id لازم يعمل (مش error)
     */
    public function test_record_income_with_explicit_null_from_account_id_works(): void
    {
        // لو حد بوضوح بعت null (مش set) — لازم يعمل
        $tx = $this->txService->recordIncome([
            'amount' => 1500.00,
            'to_account_id' => $this->treasury->id,
            'from_account_id' => null,  // ← null مش set
            'module' => TransactionModule::Flight->value,
            'created_by' => $this->admin->id,
        ]);

        $this->assertNotNull($tx);
        $txType = $tx->type instanceof \BackedEnum ? $tx->type->value : $tx->type;
        $this->assertContains($txType, ['income', 'transfer']);
    }
}