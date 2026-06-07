<?php

namespace Tests\Feature\HajjUmra;

use App\Models\Account;
use App\Models\AccountEntry;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\HajjUmraBooking;
use App\Models\Program;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Finance\LedgerBalanceMutationGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class HajjUmraApiTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Account $treasury;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::query()->create([
            'name' => 'Hajj Umrah API Tester',
            'email' => 'hajj-umrah-api@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        Employee::query()->create([
            'user_id' => $this->user->id,
            'status' => 'active',
        ]);

        Sanctum::actingAs($this->user, ['*']);

        $this->treasury = Account::query()->create([
            'name' => 'خزينة الحج والعمرة',
            'type' => 'cashbox',
            'currency' => 'EGP',
            'balance' => 100000.00,
            'is_active' => true,
            'owner_type' => Account::OWNER_TYPE_OFFICE,
            'module_type' => 'hajj_umra',
            'module' => 'hajj_umra',
            'created_by' => $this->user->id,
        ]);

        LedgerBalanceMutationGuard::run(function (): void {
            $this->treasury->update(['balance' => 100000.00]);
        });
    }

    public function test_dashboard_endpoint_returns_stats(): void
    {
        $program = $this->createProgram();
        $customer = Customer::query()->create([
            'full_name' => 'عميل الداشبورد',
            'phone' => '01090001122',
        ]);

        $this->postJson('/api/v1/hajj-umra/bookings', [
            'customer_id' => $customer->id,
            'program_id' => $program->id,
            'purchase_price' => 10000,
            'selling_price' => 12000,
            'account_id' => $this->treasury->id,
            'status' => 'confirmed',
        ])->assertCreated();

        $response = $this->getJson('/api/v1/hajj-umra/dashboard');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => [
                    'stats' => [
                        'monthly_revenue',
                        'total_bookings',
                        'cashboxes',
                        'banks',
                        'wallets',
                    ],
                    'recent_bookings',
                ],
            ])
            ->assertJsonPath('data.stats.total_bookings', 1)
            ->assertJsonPath('data.stats.monthly_revenue', 12000);
    }

    public function test_treasury_overview_lists_hajj_umra_accounts(): void
    {
        $response = $this->getJson('/api/v1/hajj-umra/treasury/overview');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => [
                    'settlement_accounts',
                    'executing_companies',
                    'recent_hajj_umra_transactions',
                ],
            ]);

        $ids = collect($response->json('data.settlement_accounts'))->pluck('id');
        $this->assertTrue($ids->contains($this->treasury->id));
    }

    public function test_booking_rejects_account_from_other_module(): void
    {
        $flightTreasury = Account::query()->create([
            'name' => 'خزينة الطيران',
            'type' => 'cashbox',
            'currency' => 'EGP',
            'balance' => 5000,
            'is_active' => true,
            'owner_type' => Account::OWNER_TYPE_OFFICE,
            'module_type' => 'flights',
            'created_by' => $this->user->id,
        ]);

        $program = $this->createProgram();
        $customer = Customer::query()->create([
            'full_name' => 'عميل التحقق',
            'phone' => '01033344455',
        ]);

        $response = $this->postJson('/api/v1/hajj-umra/bookings', [
            'customer_id' => $customer->id,
            'program_id' => $program->id,
            'purchase_price' => 10000,
            'selling_price' => 12000,
            'account_id' => $flightTreasury->id,
            'status' => 'confirmed',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['account_id']);
    }

    public function test_cancel_booking_reverses_accounting_entries(): void
    {
        $program = $this->createProgram();
        $customer = Customer::query()->create([
            'full_name' => 'عميل الإلغاء',
            'phone' => '01077788899',
        ]);

        $treasuryBefore = (float) $this->treasury->fresh()->balance;

        $createResponse = $this->postJson('/api/v1/hajj-umra/bookings', [
            'customer_id' => $customer->id,
            'program_id' => $program->id,
            'purchase_price' => 10000,
            'selling_price' => 15000,
            'account_id' => $this->treasury->id,
            'status' => 'confirmed',
            'initial_payment' => [
                'amount' => 3000,
                'payment_method' => 'cash',
            ],
        ]);

        $createResponse->assertCreated();
        $bookingId = $createResponse->json('data.id');

        $customer->refresh();
        $this->assertNotNull($customer->account_id);
        $customerBalanceAfterBooking = (float) Account::find($customer->account_id)->balance;
        $this->assertNotEquals(0.0, $customerBalanceAfterBooking);

        $bookingTxCount = Transaction::query()
            ->where('module', 'hajj_umra')
            ->where('related_type', HajjUmraBooking::class)
            ->where('related_id', $bookingId)
            ->count();
        $this->assertGreaterThanOrEqual(2, $bookingTxCount);

        $cancelResponse = $this->deleteJson("/api/v1/hajj-umra/bookings/{$bookingId}", [
            'reason' => 'طلب العميل',
        ]);

        $cancelResponse->assertOk()
            ->assertJsonPath('data.status', 'cancelled');

        $this->assertEquals(0.0, (float) Account::find($customer->account_id)->fresh()->balance);
        $this->assertEqualsWithDelta($treasuryBefore, (float) $this->treasury->fresh()->balance, 0.01);

        $remainingEntries = AccountEntry::query()
            ->whereHas('transaction', function ($q) use ($bookingId): void {
                $q->where('module', 'hajj_umra')
                    ->where('related_type', HajjUmraBooking::class)
                    ->where('related_id', $bookingId);
            })
            ->count();
        $this->assertSame(0, $remainingEntries);

        $balancesResponse = $this->getJson('/api/v1/hajj-umra/customer-balances');
        $balancesResponse->assertOk();
        $row = collect($balancesResponse->json('data'))->firstWhere('client_id', $customer->id);
        $this->assertNull($row);
    }

    public function test_pay_debt_with_hajj_umra_module_updates_customer_balances(): void
    {
        $program = $this->createProgram();
        $customer = Customer::query()->create([
            'full_name' => 'عميل السداد العام',
            'phone' => '01012121212',
        ]);

        $this->postJson('/api/v1/hajj-umra/bookings', [
            'customer_id' => $customer->id,
            'program_id' => $program->id,
            'purchase_price' => 8000,
            'selling_price' => 12000,
            'account_id' => $this->treasury->id,
            'status' => 'confirmed',
            'initial_payment' => [
                'amount' => 2000,
                'payment_method' => 'cash',
            ],
        ])->assertCreated();

        $beforeBalances = $this->getJson('/api/v1/hajj-umra/customer-balances');
        $beforeBalances->assertOk()
            ->assertJsonPath('data.0.total_sales', 12000)
            ->assertJsonPath('data.0.total_paid', 2000)
            ->assertJsonPath('data.0.total_debt', 10000);

        $payResponse = $this->postJson("/api/v1/customers/{$customer->id}/pay-debt", [
            'amount' => 1500,
            'account_id' => $this->treasury->id,
            'module' => 'hajj_umra',
            'notes' => 'سداد مديونية حج وعمرة',
        ]);

        $payResponse->assertOk()
            ->assertJsonPath('success', true);

        $afterBalances = $this->getJson('/api/v1/hajj-umra/customer-balances');
        $afterBalances->assertOk()
            ->assertJsonPath('data.0.total_sales', 12000)
            ->assertJsonPath('data.0.total_paid', 3500)
            ->assertJsonPath('data.0.total_debt', 8500);

        $statement = $this->getJson('/api/v1/hajj-umra/customer-statement?client_id='.$customer->id);
        $statement->assertOk()
            ->assertJsonPath('data.summary.total_paid', 3500)
            ->assertJsonPath('data.summary.total_debt', 8500);
    }

    public function test_update_selling_price_reposts_income_transaction(): void
    {
        $program = $this->createProgram();
        $customer = Customer::query()->create([
            'full_name' => 'عميل التعديل',
            'phone' => '01056565656',
        ]);

        $createResponse = $this->postJson('/api/v1/hajj-umra/bookings', [
            'customer_id' => $customer->id,
            'program_id' => $program->id,
            'purchase_price' => 5000,
            'selling_price' => 8000,
            'account_id' => $this->treasury->id,
            'status' => 'confirmed',
        ]);

        $bookingId = $createResponse->json('data.id');
        $originalIncomeTxId = HajjUmraBooking::find($bookingId)->income_transaction_id;

        $updateResponse = $this->patchJson("/api/v1/hajj-umra/bookings/{$bookingId}", [
            'selling_price' => 9500,
        ]);

        $updateResponse->assertOk()
            ->assertJsonPath('data.pricing.selling_price', 9500);

        $booking = HajjUmraBooking::find($bookingId);
        $this->assertNotEquals($originalIncomeTxId, $booking->income_transaction_id);
        $this->assertEquals(9500.0, (float) $booking->incomeTransaction->amount);

        $oldEntries = AccountEntry::query()
            ->where('transaction_id', $originalIncomeTxId)
            ->count();
        $this->assertSame(0, $oldEntries);
    }

    protected function createProgram(): Program
    {
        return Program::query()->create([
            'program_name' => 'برنامج اختبار',
            'program_type' => 'umrah',
            'total_nights' => 10,
            'mecca_hotel_name' => 'فندق',
            'mecca_nights' => 5,
            'medina_hotel_name' => 'فندق',
            'medina_nights' => 5,
            'airline' => 'مصر للطيران',
            'executing_company' => 'شركة',
            'trip_supervisor' => 'مشرف',
            'accommodation_type' => 'QUAD',
            'default_purchase_price' => 10000,
            'default_selling_price' => 12000,
            'departure_date' => now()->addDays(10)->toDateString(),
            'return_date' => now()->addDays(20)->toDateString(),
            'departure_point' => 'Cairo',
            'is_active' => true,
        ]);
    }
}
