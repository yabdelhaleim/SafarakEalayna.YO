<?php

namespace Tests\Feature;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\Online\OnlineServiceType;
use App\Models\Setting\PaymentMethod;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OnlinePaymentAccountSelectionTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected OnlineServiceType $serviceType;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::query()->create([
            'name' => 'Online Payment Tester',
            'email' => 'online-payment@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        Sanctum::actingAs($this->user, ['*']);

        PaymentMethod::query()->forceDelete();
        $this->createPaymentMethod('cash', 'نقدي', 1);
        $this->createPaymentMethod('bank_transfer', 'تحويل بنكي', 2);
        $this->createPaymentMethod('mobile_money', 'محفظة إلكترونية', 3);

        $this->serviceType = OnlineServiceType::query()->create([
            'code' => 'payment-account-test',
            'name_ar' => 'خدمة اختبار التحصيل',
            'name_en' => 'Payment account test',
            'is_active' => true,
            'order' => 1,
            'created_by' => $this->user->id,
        ]);
    }

    public function test_online_settings_return_only_office_liquidity_accounts_with_method_account_types(): void
    {
        $cashbox = $this->createAccount('خزينة المكتب', AccountType::Cashbox, 'office');
        $bank = $this->createAccount('بنك المكتب', AccountType::Bank, 'office');
        $wallet = $this->createAccount('محفظة المكتب', AccountType::Wallet, 'office');
        $tourism = $this->createAccount('خزينة السياحة', AccountType::Cashbox, 'tourism');
        $inactive = $this->createAccount('بنك مكتب غير نشط', AccountType::Bank, 'office', false);
        $customer = $this->createAccount(
            'حساب عميل أونلاين',
            AccountType::Customer,
            'online',
            true,
            Account::OWNER_TYPE_OWNER,
        );

        $response = $this->getJson('/api/v1/online/settings/all');

        $response->assertOk();

        $accountIds = collect($response->json('data.accounts'))->pluck('id')->all();
        $this->assertContains($cashbox->id, $accountIds);
        $this->assertContains($bank->id, $accountIds);
        $this->assertContains($wallet->id, $accountIds);
        $this->assertNotContains($tourism->id, $accountIds);
        $this->assertNotContains($inactive->id, $accountIds);
        $this->assertNotContains($customer->id, $accountIds);

        $methods = collect($response->json('data.payment_methods'))->keyBy('code');
        $this->assertSame('cashbox', $methods->get('cash')['account_type']);
        $this->assertSame('bank', $methods->get('bank_transfer')['account_type']);
        $this->assertSame('wallet', $methods->get('mobile_money')['account_type']);
    }

    public function test_online_transaction_rejects_an_account_that_does_not_match_the_payment_method(): void
    {
        $bank = $this->createAccount('بنك المكتب', AccountType::Bank, 'office');

        $this->postJson('/api/v1/online/transactions', $this->validPayload([
            'payment_method' => 'cash',
            'account_id' => $bank->id,
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('account_id');
    }

    public function test_online_transaction_rejects_a_tourism_account(): void
    {
        $tourismCashbox = $this->createAccount('خزينة السياحة', AccountType::Cashbox, 'tourism');

        $this->postJson('/api/v1/online/transactions', $this->validPayload([
            'payment_method' => 'cash',
            'account_id' => $tourismCashbox->id,
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('account_id');
    }

    public function test_online_transaction_accepts_a_matching_account_and_checks_partial_updates(): void
    {
        $cashbox = $this->createAccount('خزينة المكتب', AccountType::Cashbox, 'office');

        $createResponse = $this->postJson('/api/v1/online/transactions', $this->validPayload([
            'payment_method' => 'cash',
            'account_id' => $cashbox->id,
        ]));

        $createResponse->assertCreated();
        $transactionId = $createResponse->json('data.id');

        $this->patchJson("/api/v1/online/transactions/{$transactionId}", [
            'payment_method' => 'bank_transfer',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('account_id');
    }

    private function createPaymentMethod(string $code, string $name, int $order): PaymentMethod
    {
        return PaymentMethod::query()->create([
            'code' => $code,
            'name_ar' => $name,
            'name_en' => $code,
            'is_active' => true,
            'order' => $order,
        ]);
    }

    private function createAccount(
        string $name,
        AccountType $type,
        string $moduleType,
        bool $active = true,
        string $ownerType = Account::OWNER_TYPE_OFFICE,
    ): Account {
        return Account::query()->create([
            'name' => $name,
            'type' => $type,
            'balance' => 0,
            'currency' => 'EGP',
            'is_active' => $active,
            'owner_type' => $ownerType,
            'module_type' => $moduleType,
            'created_by' => $this->user->id,
        ]);
    }

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'service_type_id' => $this->serviceType->id,
            'customer_name' => 'عميل اختبار التحصيل',
            'customer_phone' => '01000000000',
            'purchase_price' => 0,
            'selling_price' => 0,
            'amount_paid' => 0,
            'payment_method' => 'cash',
            'account_id' => null,
        ], $overrides);
    }
}
