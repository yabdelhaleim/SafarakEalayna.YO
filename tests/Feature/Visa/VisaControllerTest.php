<?php

namespace Tests\Feature\Visa;

use App\Enums\VisaEntryType;
use App\Enums\VisaStatus;
use App\Enums\VisaType;
use App\Models\Account;
use App\Models\Customer;
use App\Models\HajjUmra\VisaDuration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Decomposed per-action tests for Api\V1\VisaController (customer endpoints).
 *
 * Tests the slimmed-down controller that owns:
 *  - customerBalances (AR rollup per customer)
 *  - customerStatement (ledger detail per customer)
 *  - payCustomerDebt (cashbook-side payment)
 *
 * Booking CRUD lives in VisaBookingController (covered separately).
 *
 * @see \App\Http\Controllers\Api\V1\VisaController
 */
class VisaControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Account $treasury;

    protected VisaDuration $duration;

    protected Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::query()->create([
            'name' => 'Visa Controller Tester',
            'email' => 'visa-controller@'.now()->timestamp.'.test',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);
        Sanctum::actingAs($this->user, ['*']);

        $this->treasury = Account::query()->create([
            'name' => 'Visa Treasury',
            'type' => 'cashbox',
            'currency' => 'EGP',
            'balance' => 100000.00,
            'is_active' => true,
            'owner_type' => Account::OWNER_TYPE_OFFICE,
            'module_type' => 'tourism',
            'module' => 'visas',
            'is_module_vault' => true,
            'created_by' => $this->user->id,
        ]);

        $this->duration = VisaDuration::query()->create([
            'code' => '6m_single',
            'label_ar' => '6 أشهر',
            'label_en' => '6 months',
            'months' => 6,
            'is_active' => true,
        ]);

        $this->customer = Customer::query()->create([
            'full_name' => 'Visa Test Customer',
            'phone' => '01000000099',
        ]);
    }

    protected function createVisaBooking(string $status = 'submitted', float $sellingPrice = 7000): \App\Models\VisaBooking
    {
        // Use the HTTP endpoint so the service layer wires visa_detail_id and profit correctly
        $response = $this->postJson('/api/v1/visa/bookings', [
            'customer_id' => $this->customer->id,
            'purchase_price' => 5000,
            'selling_price' => $sellingPrice,
            'service_fee' => 0,
            'currency' => 'EGP',
            'account_id' => $this->treasury->id,
            'status' => $status,
            'visa_details' => [
                'visa_type' => VisaType::Work->value,
                'country' => 'USA',
                'duration' => '6 months',
                'visa_duration_id' => $this->duration->id,
                'entry_type' => VisaEntryType::Single->value,
            ],
        ]);

        $response->assertCreated();

        return \App\Models\VisaBooking::query()->findOrFail($response->json('data.id'));
    }

    /* =========================================================
     * CUSTOMER BALANCES
     * ========================================================= */

    public function test_customer_balances_returns_list(): void
    {
        $this->createVisaBooking();

        $response = $this->getJson('/api/v1/visa/customer-balances');

        $response->assertOk();
        $this->assertIsArray($response->json('data'));
    }

    public function test_customer_balances_filters_by_search(): void
    {
        $this->createVisaBooking();

        $response = $this->getJson('/api/v1/visa/customer-balances?search=Visa');

        $response->assertOk();
        $this->assertGreaterThanOrEqual(1, count($response->json('data')));
    }

    public function test_customer_balances_filters_debtors_only(): void
    {
        $this->createVisaBooking('submitted', 7000); // unpaid → debt

        $response = $this->getJson('/api/v1/visa/customer-balances?status=debtors');

        $response->assertOk();
        foreach ($response->json('data') as $row) {
            $this->assertGreaterThan(0, $row['total_debt']);
        }
    }

    public function test_customer_balances_excludes_cancelled_bookings(): void
    {
        $this->createVisaBooking('cancelled', 7000);

        $response = $this->getJson('/api/v1/visa/customer-balances');

        $this->assertCount(0, $response->json('data'));
    }

    /* =========================================================
     * CUSTOMER STATEMENT
     * ========================================================= */

    public function test_customer_statement_requires_client_id(): void
    {
        $response = $this->getJson('/api/v1/visa/customer-statement');

        $response->assertStatus(400);
    }

    public function test_customer_statement_returns_summary_for_known_customer(): void
    {
        $this->createVisaBooking();

        $response = $this->getJson("/api/v1/visa/customer-statement?client_id={$this->customer->id}");

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'customer' => ['id', 'name'],
                    'summary' => ['total_sales', 'total_paid', 'total_debt'],
                    'transactions',
                ],
            ]);

        $this->assertGreaterThan(0, $response->json('data.summary.total_sales'));
    }

    public function test_customer_statement_returns_404_for_unknown_customer(): void
    {
        $response = $this->getJson('/api/v1/visa/customer-statement?client_id=999999');

        $response->assertStatus(422);
    }

    /* =========================================================
     * PAY CUSTOMER DEBT
     * ========================================================= */

    public function test_pay_customer_debt_records_transaction(): void
    {
        $booking = $this->createVisaBooking();
        // Give customer a ledger account via the customer ledger observer
        $customerAccount = Account::query()->create([
            'name' => 'حساب العميل: '.$this->customer->full_name,
            'type' => 'customer',
            'currency' => 'EGP',
            'balance' => -3000,
            'is_active' => true,
            'owner_type' => Account::OWNER_TYPE_OWNER,
            'module_type' => 'visas',
            'created_by' => $this->user->id,
        ]);
        $this->customer->update(['account_id' => $customerAccount->id]);

        $response = $this->postJson("/api/v1/visa/customers/{$this->customer->id}/pay-debt", [
            'amount' => 3000,
            'account_id' => $this->treasury->id,
            'notes' => 'test pay debt',
        ]);

        $response->assertOk()
            ->assertJsonStructure(['data' => ['transaction_id', 'new_balance']]);
    }

    public function test_pay_customer_debt_validates_amount_required(): void
    {
        $response = $this->postJson("/api/v1/visa/customers/{$this->customer->id}/pay-debt", [
            'account_id' => $this->treasury->id,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['amount']);
    }

    public function test_pay_customer_debt_validates_account_required(): void
    {
        $response = $this->postJson("/api/v1/visa/customers/{$this->customer->id}/pay-debt", [
            'amount' => 1000,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['account_id']);
    }

    public function test_pay_customer_debt_validates_amount_min(): void
    {
        $response = $this->postJson("/api/v1/visa/customers/{$this->customer->id}/pay-debt", [
            'amount' => 0,
            'account_id' => $this->treasury->id,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['amount']);
    }
}