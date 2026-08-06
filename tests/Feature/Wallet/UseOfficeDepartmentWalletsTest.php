<?php

namespace Tests\Feature\Wallet;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\User;
use App\Models\Wallet\WalletTransaction;
use App\Models\Wallet\WalletType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Feature test for: allowing the Wallet & Transfer module to use
 * BOTH "official module wallets" (`module='wallet_transfer'`)
 * AND "office department wallets" (`module_type='office'` general).
 *
 * Locks in:
 *   - Backend (TransferLiquidityAccount rule + TransferTreasuryController)
 *     accept both scopes (production-safe, no breaking changes).
 *   - Audit log captures `wallet_account_scope` = 'official_module' | 'office_department'
 *     so operators can trace which scope was used for every operation.
 *   - Accounting behavior (balance movement) is identical regardless of scope.
 *
 * @see \App\Rules\TransferLiquidityAccount
 * @see \App\Services\Wallet\WalletTransactionService
 */
class UseOfficeDepartmentWalletsTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected Customer $customer;

    protected WalletType $walletType;

    protected Account $officialModuleWallet;     // module='wallet_transfer'

    protected Account $officeDepartmentWallet;   // module_type='office' only

    protected Account $cashbox;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::query()->create([
            'name' => 'Office Scope Tester',
            'email' => 'office-scope@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        Sanctum::actingAs($this->admin, ['*']);

        $this->customer = Customer::query()->create([
            'full_name' => 'عميل تجربة النطاق',
            'phone' => '01000000100',
            'national_id' => '42345678901299',
            'module_type' => 'wallet_transfer',
            'created_by' => $this->admin->id,
        ]);

        $this->walletType = WalletType::query()->create([
            'name' => 'فودافون كاش',
            'code' => 'vodafone_cash',
            'is_active' => true,
            'sort_order' => 1,
        ]);

        // المحفظة الرسمية للموديول (الحالية — بتفلتر wallet_transfer)
        $this->officialModuleWallet = Account::query()->create([
            'name' => 'محفظة فودافون كاش — رسمية',
            'type' => AccountType::Wallet->value,
            'currency' => 'EGP',
            'balance' => 10000,
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'office',
            'module' => 'wallet_transfer',  // ⭐ المفتاح: المحفظة الرسمية للموديول
            'wallet_provider' => 'vodafone_cash',
            'wallet_number' => '01000000001',
            'created_by' => $this->admin->id,
        ]);

        // محفظة قسم المكتب العامة (جديدة — مع تغيير الـ scope)
        $this->officeDepartmentWallet = Account::query()->create([
            'name' => 'محفظة فودافون كاش — قسم المكتب',
            'type' => AccountType::Wallet->value,
            'currency' => 'EGP',
            'balance' => 8000,
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'office',
            // 'module' => null — لا يوجد وسم wallet_transfer
            'wallet_provider' => 'vodafone_cash',
            'wallet_number' => '01000000002',
            'created_by' => $this->admin->id,
        ]);

        $this->cashbox = Account::query()->create([
            'name' => 'خزينة رئيسية',
            'type' => AccountType::Cashbox->value,
            'currency' => 'EGP',
            'balance' => 50000,
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'office',
            'created_by' => $this->admin->id,
        ]);
    }

    /**
     * السيناريو الأساسي: المستخدم يقدر يستخدم محفظة قسم المكتب العامة
     * في عملية إرسال رصيد، والنظام يقبلها + يسجلها في الـ Audit log
     * بـ scope='office_department' + يحدث الأرصدة بشكل صحيح.
     */
    public function test_user_can_use_office_department_wallet_in_send_transaction(): void
    {
        $payload = [
            'wallet_type_id' => $this->walletType->id,
            'wallet_number' => '01012345678',
            'customer_id' => $this->customer->id,
            'customer_name' => $this->customer->full_name,
            'type' => 'send',
            'amount' => 500.0,
            'service_fee' => 5.0,
            'wallet_account_id' => $this->officeDepartmentWallet->id, // ⭐ محفظة قسم المكتب
            'cash_account_id' => $this->cashbox->id,
            'notes' => 'تيست محفظة قسم المكتب',
        ];

        $response = $this->postJson('/api/v1/wallet/transactions', $payload);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'success', 'message',
                'data' => ['id', 'type', 'amount', 'service_fee', 'total_amount'],
            ]);

        $txId = $response->json('data.id');

        // 1) العملية اتسجلت في wallet_transactions
        $this->assertDatabaseHas('wallet_transactions', [
            'id' => $txId,
            'type' => 'send',
            'wallet_account_id' => $this->officeDepartmentWallet->id,
            'amount' => 500.0,
        ]);

        // 2) الـ Audit log اتكتب بـ scope صحيح
        $audit = AuditLog::query()
            ->where('model_type', WalletTransaction::class)
            ->where('model_id', $txId)
            ->where('action', 'wallet_transaction.created')
            ->first();

        $this->assertNotNull($audit, 'يجب كتابة Audit log للعملية');
        $this->assertEquals('office_department', $audit->new_values['wallet_account_scope'],
            'يجب أن يكون الـ scope = office_department لأن المحفظة عامة لقسم المكتب');
        $this->assertEquals($this->officeDepartmentWallet->name, $audit->new_values['wallet_account_name']);

        // 3) الأرصدة اتحدثت بشكل صحيح (نفس منطق محفظة رسمية)
        $this->assertDatabaseHas('accounts', [
            'id' => $this->officeDepartmentWallet->id,
            'balance' => 8000 - 500, // المبلغ اتخصم من محفظة المكتب
        ]);
        $this->assertDatabaseHas('accounts', [
            'id' => $this->cashbox->id,
            'balance' => 50000 + 505, // المبلغ + الرسوم اتزادوا في الخزينة
        ]);
    }

    /**
     * Backward compatibility: المحفظة الرسمية للموديول (الحالية)
     * لازم تفضل تشتغل بنفس الطريقة، والـ Audit log لازم يميزها بـ scope='official_module'.
     */
    public function test_official_module_wallet_still_works_and_audited_correctly(): void
    {
        $payload = [
            'wallet_type_id' => $this->walletType->id,
            'wallet_number' => '01012345679',
            'customer_id' => $this->customer->id,
            'customer_name' => $this->customer->full_name,
            'type' => 'send',
            'amount' => 300.0,
            'service_fee' => 3.0,
            'wallet_account_id' => $this->officialModuleWallet->id, // ⭐ المحفظة الرسمية
            'cash_account_id' => $this->cashbox->id,
            'notes' => 'تيست المحفظة الرسمية',
        ];

        $response = $this->postJson('/api/v1/wallet/transactions', $payload);

        $response->assertStatus(201);

        $txId = $response->json('data.id');

        // Audit log لازم يكون scope=official_module
        $audit = AuditLog::query()
            ->where('model_type', WalletTransaction::class)
            ->where('model_id', $txId)
            ->where('action', 'wallet_transaction.created')
            ->first();

        $this->assertNotNull($audit);
        $this->assertEquals('official_module', $audit->new_values['wallet_account_scope'],
            'محفظة wallet_transfer الرسمية لازم يكون scope بتاعها official_module');
    }

    /**
     * استقبال رصيد: لو المستخدم استقبل رصيد في محفظة قسم المكتب،
     * الرصيد لازم يزيد (نفس المنطق كأنها محفظة رسمية).
     */
    public function test_office_department_wallet_works_for_receive_transaction(): void
    {
        $payload = [
            'wallet_type_id' => $this->walletType->id,
            'wallet_number' => '01012345680',
            'customer_id' => $this->customer->id,
            'customer_name' => $this->customer->full_name,
            'type' => 'receive',
            'amount' => 1000.0,
            'service_fee' => 10.0,
            'wallet_account_id' => $this->officeDepartmentWallet->id,
            'cash_account_id' => $this->cashbox->id,
            'notes' => 'تيست استقبال',
        ];

        $response = $this->postJson('/api/v1/wallet/transactions', $payload);

        $response->assertStatus(201);

        $txId = $response->json('data.id');

        $this->assertDatabaseHas('accounts', [
            'id' => $this->officeDepartmentWallet->id,
            'balance' => 8000 + 1000, // المبلغ اتزاد في محفظة المكتب
        ]);

        // Audit لازم يكون موجود بنطاق صحيح
        $audit = AuditLog::query()
            ->where('model_id', $txId)
            ->where('action', 'wallet_transaction.created')
            ->firstOrFail();

        $this->assertEquals('office_department', $audit->new_values['wallet_account_scope']);
        $this->assertEquals('receive', $audit->new_values['type']);
    }

    /**
     * Backward compatibility للـ cross-module isolation test:
     * حساب العميل لسه بيتعمل tag بـ module_type='wallet_transfer'
     * حتى لو المستخدم استخدم محفظة قسم المكتب العامة.
     */
    public function test_office_department_wallet_still_tags_customer_with_wallet_module(): void
    {
        $payload = [
            'wallet_type_id' => $this->walletType->id,
            'wallet_number' => '01012345681',
            'customer_id' => $this->customer->id,
            'customer_name' => $this->customer->full_name,
            'type' => 'send',
            'amount' => 200.0,
            'service_fee' => 2.0,
            'wallet_account_id' => $this->officeDepartmentWallet->id,
            'cash_account_id' => $this->cashbox->id,
        ];

        $response = $this->postJson('/api/v1/wallet/transactions', $payload);

        $response->assertStatus(201);

        $customerAccount = $this->customer->fresh()->ledgerAccount;
        $this->assertNotNull($customerAccount);
        $this->assertSame('wallet_transfer', $customerAccount->module_type,
            'حساب العميل لازم يفضل module_type=wallet_transfer حتى لو المحفظة عامة');
    }

    /**
     * ضمان سلامة المحاسبة: حذف العملية لازم يرجع الأرصدة لقيمها الأصلية
     * سواء كانت المحفظة رسمية أو عامة لقسم المكتب.
     */
    public function test_delete_office_department_wallet_transaction_reverses_accounting(): void
    {
        $payload = [
            'wallet_type_id' => $this->walletType->id,
            'wallet_number' => '01012345682',
            'customer_id' => $this->customer->id,
            'customer_name' => $this->customer->full_name,
            'type' => 'send',
            'amount' => 400.0,
            'service_fee' => 4.0,
            'wallet_account_id' => $this->officeDepartmentWallet->id,
            'cash_account_id' => $this->cashbox->id,
        ];

        $response = $this->postJson('/api/v1/wallet/transactions', $payload);
        $response->assertStatus(201);

        $txId = $response->json('data.id');

        // بعد الإنشاء، الرصيد اتغير
        $this->assertDatabaseHas('accounts', [
            'id' => $this->officeDepartmentWallet->id,
            'balance' => 8000 - 400,
        ]);

        // احذف العملية
        $deleteResp = $this->deleteJson("/api/v1/wallet/transactions/{$txId}");
        $deleteResp->assertStatus(200);

        // الرصيد رجع لأصله
        $this->assertDatabaseHas('accounts', [
            'id' => $this->officeDepartmentWallet->id,
            'balance' => 8000,
        ]);
        $this->assertDatabaseHas('accounts', [
            'id' => $this->cashbox->id,
            'balance' => 50000,
        ]);

        // Audit log للحذف اتكتب
        $deleteAudit = AuditLog::query()
            ->where('model_id', $txId)
            ->where('action', 'wallet_transaction.deleted')
            ->first();

        $this->assertNotNull($deleteAudit,
            'يجب كتابة Audit log للحذف يحتوي على الـ scope القديم');
        $this->assertEquals('office_department', $deleteAudit->old_values['wallet_account_scope']);
    }
}
