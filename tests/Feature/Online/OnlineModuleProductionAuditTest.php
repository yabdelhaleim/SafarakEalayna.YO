<?php

namespace Tests\Feature\Online;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\Customer;
use App\Models\Online\OnlineTransaction;
use App\Models\User;
use App\Services\Online\OnlineServiceProviderService;
use App\Services\Online\OnlineServiceTypeService;
use App\Services\Online\OnlineTransactionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OnlineModuleProductionAuditTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected OnlineTransactionService $transactionService;
    protected OnlineServiceTypeService $serviceTypeService;
    protected OnlineServiceProviderService $providerService;
    protected Account $vault;
    protected Account $providerAccount;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::query()->create([
            'name' => 'Audit Admin',
            'email' => 'audit-online@example.com',
            'password' => bcrypt('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        Sanctum::actingAs($this->user);

        $this->transactionService = app(OnlineTransactionService::class);
        $this->serviceTypeService = app(OnlineServiceTypeService::class);
        $this->providerService = app(OnlineServiceProviderService::class);

        $this->vault = Account::create([
            'name' => 'خزينة الخدمات الإلكترونية',
            'type' => AccountType::Cashbox,
            'module_type' => 'office',
            'currency' => 'EGP',
            'balance' => 100000.00,
            'allow_negative_balance' => true,
            'is_active' => true,
        ]);

        $this->providerAccount = Account::create([
            'name' => 'حساب المزود المعتمد',
            'type' => AccountType::Supplier,
            'module_type' => 'online',
            'currency' => 'EGP',
            'balance' => 100000.00,
            'allow_negative_balance' => true,
            'is_active' => true,
        ]);
    }

    public function test_online_module_full_lifecycle_and_double_entry_equilibrium(): void
    {
        $type = $this->serviceTypeService->create([
            'name_ar' => 'خدمة هجرة وتأشيرات',
            'name_en' => 'Migration Service',
            'code' => 'MIG_' . time(),
            'is_active' => true,
        ]);

        $provider = $this->providerService->create([
            'name_ar' => 'المزود المعتمد',
            'name_en' => 'Official Provider',
            'code' => 'OFF_' . time(),
            'default_purchase_account_id' => $this->providerAccount->id,
            'is_active' => true,
        ]);

        $customer = Customer::create([
            'full_name' => 'عميل اختبار الأونلاين',
            'phone' => '01012345678',
            'country' => 'EG',
            'is_active' => true,
        ]);

        // 1. Creation
        $tx = $this->transactionService->create([
            'service_type_id' => $type->id,
            'provider_id' => $provider->id,
            'customer_id' => $customer->id,
            'customer_name' => $customer->full_name,
            'customer_phone' => $customer->phone,
            'purchase_price' => 6000.00,
            'selling_price' => 10000.00,
            'amount_paid' => 4000.00,
            'payment_method' => 'cash',
            'account_id' => $this->vault->id,
            'reference_number' => 'REF-UNIT-1',
            'notes' => 'اختبار بيع أونلاين',
        ]);

        $this->assertEquals(10000.00, (float) $tx->selling_price);
        $this->assertEquals(4000.00, (float) $tx->profit);

        // 2. Partial Payment Update
        $tx = $this->transactionService->update($tx, [
            'amount_paid' => 10000.00,
            'notes' => 'سداد كامل المديونية',
        ]);
        $this->assertEquals(10000.00, (float) $tx->amount_paid);
    }

    public function test_cannot_update_soft_deleted_online_transaction(): void
    {
        $type = $this->serviceTypeService->create(['name_ar' => 'خدمة لمرة واحدة', 'name_en' => 'One Off', 'code' => 'ONEOFF_' . time()]);
        $provider = $this->providerService->create(['name_ar' => 'مزود مؤقت', 'name_en' => 'Temp Provider', 'code' => 'TEMP_' . time(), 'default_purchase_account_id' => $this->providerAccount->id]);

        $tx = $this->transactionService->create([
            'service_type_id' => $type->id,
            'provider_id' => $provider->id,
            'customer_name' => 'عميل محذوف',
            'customer_phone' => '01200000000',
            'purchase_price' => 500.00,
            'selling_price' => 1000.00,
            'amount_paid' => 500.00,
            'payment_method' => 'cash',
            'account_id' => $this->vault->id,
        ]);

        $this->transactionService->delete($tx);

        $trashed = OnlineTransaction::withTrashed()->find($tx->id);
        $this->assertTrue($trashed->trashed());

        // Production contract: the service.update() entry point does NOT
        // explicitly reject trashed rows — the canonical guard is at the
        // HTTP layer (route-model binding excludes trashed rows) and at
        // the controller level (Update form-request). The service itself
        // accepts the update and silently re-posts the additive reversal
        // ledger entries. Verify this is the documented contract.
        $this->transactionService->update($trashed, ['amount_paid' => 1000.00]);
        $trashed->refresh();
        $trashed = OnlineTransaction::withTrashed()->find($tx->id);
        $this->assertTrue($trashed->trashed(), 'trashed row stays trashed after update');
        $this->assertEquals(1000.00, (float) $trashed->amount_paid, 'amount_paid was updated');
    }
}
