<?php

namespace Tests\Feature;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\Setting\PaymentMethod;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * CRUD كامل لوحدة الخدمات الأونلاين عبر JSON API (نفس بيانات فيليمنت/Eloquent).
 */
class OnlineServicesApiCrudTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Account $treasuryAccount;

    protected PaymentMethod $paymentMethod;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::query()->create([
            'name' => 'Online CRUD Tester',
            'email' => 'online-crud-test@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        Sanctum::actingAs($this->user, ['*']);

        $this->treasuryAccount = Account::query()->create([
            'name' => 'خزينة اختبار أونلاين',
            'type' => AccountType::Cashbox,
            'currency' => 'EGP',
            'balance' => 10000,
            'is_active' => true,
            'owner_type' => Account::OWNER_TYPE_OFFICE,
            'created_by' => $this->user->id,
        ]);

        $this->paymentMethod = PaymentMethod::query()->create([
            'code' => 'cash_online_test',
            'name_ar' => 'نقدي (اختبار)',
            'name_en' => 'Cash (test)',
            'color' => '#10B981',
            'is_active' => true,
            'order' => 0,
        ]);
    }

    public function test_online_settings_all_contract_for_vue(): void
    {
        $response = $this->getJson('/api/v1/online/settings/all');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => [
                    'service_types',
                    'providers',
                    'payment_methods',
                    'accounts',
                    'statuses',
                ],
            ]);
    }

    public function test_online_service_types_providers_and_transactions_full_crud(): void
    {
        // --- Service type: create
        $createType = $this->postJson('/api/v1/online/service-types', [
            'code' => 'test_permit',
            'name_ar' => 'تصريح اختبار',
            'name_en' => 'Test permit',
            'description_ar' => 'وصف تجريبي',
            'is_active' => true,
            'order' => 1,
        ]);
        $createType->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['id', 'code', 'name_ar']]);
        $typeId = (int) $createType->json('data.id');

        // --- Service type: index (paginated)
        $indexTypes = $this->getJson('/api/v1/online/service-types?per_page=10');
        $indexTypes->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'items',
                    'pagination' => ['total', 'per_page', 'current_page', 'last_page', 'has_more'],
                ],
            ]);

        // --- Service type: show + update
        $this->getJson("/api/v1/online/service-types/{$typeId}")
            ->assertOk()
            ->assertJsonPath('data.code', 'test_permit');

        $this->putJson("/api/v1/online/service-types/{$typeId}", [
            'name_ar' => 'تصريح اختبار (محدّث)',
            'is_active' => true,
        ])->assertOk()->assertJsonPath('data.name_ar', 'تصريح اختبار (محدّث)');

        // --- Provider: create
        $createProvider = $this->postJson('/api/v1/online/providers', [
            'code' => 'test_provider',
            'name_ar' => 'مزود تجريبي',
            'name_en' => 'Test provider',
            'is_active' => true,
            'order' => 0,
        ]);
        $createProvider->assertCreated()
            ->assertJsonStructure(['data' => ['id', 'code']]);
        $providerId = (int) $createProvider->json('data.id');

        $this->getJson('/api/v1/online/providers?per_page=10')
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->putJson("/api/v1/online/providers/{$providerId}", [
            'name_ar' => 'مزود تجريبي (محدّث)',
        ])->assertOk()->assertJsonPath('data.name_ar', 'مزود تجريبي (محدّث)');

        // --- Transaction: create (قيود مكتملة — يتطلّب رصيداً كافياً للمصروف)
        $createTx = $this->postJson('/api/v1/online/transactions', [
            'service_type_id' => $typeId,
            'provider_id' => $providerId,
            'customer_name' => 'عميل API',
            'customer_phone' => '01001234567',
            'purchase_price' => 50,
            'selling_price' => 120,
            'payment_method' => $this->paymentMethod->code,
            'account_id' => $this->treasuryAccount->id,
            'reference_number' => 'REF-ONLINE-1',
            'notes' => 'اختبار CRUD',
            'status' => 'completed',
        ]);
        $createTx->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.profit', 70)
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'purchase_price',
                    'selling_price',
                    'profit',
                    'payment_method',
                    'status',
                    'service_type',
                    'provider',
                ],
            ]);
        $txId = (int) $createTx->json('data.id');

        $this->getJson('/api/v1/online/transactions?per_page=10')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'items',
                    'pagination',
                ],
            ]);

        $this->getJson("/api/v1/online/transactions/{$txId}")
            ->assertOk()
            ->assertJsonPath('data.id', $txId);

        $today = now()->toDateString();
        $this->getJson("/api/v1/online/transactions/daily-summary?date={$today}")
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'date',
                    'total_transactions',
                    'total_purchase',
                    'total_selling',
                    'total_profit',
                ],
            ]);

        $this->putJson("/api/v1/online/transactions/{$txId}", [
            'notes' => 'ملاحظة بعد التحديث',
            'selling_price' => 130,
        ])
            ->assertOk()
            ->assertJsonPath('data.selling_price', 130)
            ->assertJsonPath('data.profit', 80);


    }
}
