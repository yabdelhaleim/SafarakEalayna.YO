<?php

namespace Tests\Feature\TourismDivision;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\AccountEntry;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Finance\LedgerBalanceMutationGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Shared base for the Tourism-Division production test suite.
 *
 * Convention reminders:
 *  - Account::balance = SUM(debit) - SUM(credit) on its AccountEntry rows.
 *  - Customer AR > 0  ⇒ العميل عليه مديونية.
 *  - Customer AR < 0  ⇒ عندنا رصيد دائن للعميل.
 *  - Supplier AP > 0  ⇒ إحنا مديونين للمورّد.
 *  - Liquidity accounts (cashbox/wallet/bank) MUST have module_type in
 *    {'office','tourism'}; subject accounts MUST have a specific module.
 *  - All API responses use `success` (not `status`) as the boolean flag.
 */
abstract class TourismTestCase extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Account $cashbox;
    protected Account $bank;
    protected Account $wallet;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::query()->create([
            'name' => 'Tourism Production Tester',
            'email' => 'tourism-prod-'.uniqid().'@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        Employee::query()->create([
            'user_id' => $this->user->id,
            'status' => 'active',
        ]);

        Sanctum::actingAs($this->user, ['*']);

        $this->cashbox = $this->makeAccount('cashbox', 'خزينة سياحية نقدية', 'tourism', 100000.00);
        $this->bank = $this->makeAccount('bank', 'بنك سياحي', 'tourism', 250000.00);
        $this->wallet = $this->makeAccount('wallet', 'محفظة سياحية', 'tourism', 50000.00);
    }

    /**
     * Create an Account and materialize the opening balance as a ledger entry
     * (so balance-equals-ledger invariant holds from t=0).
     */
    protected function makeAccount(
        string $type,
        string $name,
        string $moduleType = 'tourism',
        float $openingBalance = 0.0,
        string $currency = 'EGP',
        bool $isVault = false,
        ?string $module = null,
    ): Account {
        $account = null;
        LedgerBalanceMutationGuard::run(function () use (&$account, $type, $name, $moduleType, $openingBalance, $currency, $isVault, $module) {
            $account = Account::query()->create([
                'name' => $name,
                'type' => $type,
                'currency' => $currency,
                'balance' => 0.00,
                'is_active' => true,
                'owner_type' => Account::OWNER_TYPE_OFFICE,
                'module_type' => $moduleType,
                'module' => $module,
                'is_module_vault' => $isVault,
                'created_by' => $this->user->id,
            ]);

            if ($openingBalance !== 0.0) {
                AccountEntry::query()->create([
                    'account_id' => $account->id,
                    'transaction_id' => null,
                    'debit' => $openingBalance,
                    'credit' => 0.00,
                    'balance_after' => $openingBalance,
                    'notes' => 'رصيد افتتاحي',
                ]);
                $account->update(['balance' => $openingBalance]);
            }
        });

        return $account;
    }

    protected function makeCustomer(string $moduleType = 'tourism', array $overrides = []): Customer
    {
        $customer = Customer::query()->create(array_merge([
            'full_name' => 'عميل اختبار '.uniqid(),
            'phone' => '010'.random_int(10000000, 99999999),
            'module_type' => $moduleType,
        ], $overrides));

        if (! $customer->account_id) {
            $account = $this->makeAccount('customer', 'حساب العميل: '.$customer->full_name, $moduleType);
            $customer->update(['account_id' => $account->id]);
        }

        return $customer->refresh();
    }

    protected function assertTransactionBalanced(Transaction $tx, string $message = ''): void
    {
        $entries = AccountEntry::query()->where('transaction_id', $tx->id)->get();
        $debit = (float) $entries->sum('debit');
        $credit = (float) $entries->sum('credit');

        $this->assertGreaterThan(
            0,
            $entries->count(),
            "Transaction #{$tx->id} has no AccountEntry rows. {$message}"
        );
        $this->assertEqualsWithDelta(
            $debit,
            $credit,
            0.02,
            "Transaction #{$tx->id} is not balanced: debit={$debit}, credit={$credit}. {$message}"
        );
    }

    protected function assertAccountLedgerConsistent(int $accountId, string $context = ''): void
    {
        $entries = AccountEntry::query()->where('account_id', $accountId)->get();
        $net = (float) $entries->sum('debit') - (float) $entries->sum('credit');
        $expectedBalance = (float) Account::find($accountId)->balance;

        $this->assertEqualsWithDelta(
            $net,
            $expectedBalance,
            0.02,
            "Account #{$accountId}: stored balance {$expectedBalance} != ledger net {$net}. {$context}"
        );
    }

    protected function assertCustomerBalance(Customer $customer, float $expected, string $context = ''): void
    {
        $actual = (float) $customer->ledgerAccount()->first()->balance;
        $this->assertEqualsWithDelta(
            $expected,
            $actual,
            0.02,
            "Customer #{$customer->id} balance: expected {$expected}, actual {$actual}. {$context}"
        );
    }

    protected function assertCashboxDelta(float $expectedDelta, string $context = ''): void
    {
        $delta = (float) $this->cashbox->fresh()->balance - 100000.00;
        $this->assertEqualsWithDelta(
            $expectedDelta,
            $delta,
            0.02,
            "Cashbox delta: expected {$expectedDelta}, actual {$delta}. {$context}"
        );
    }

    protected function transactionsForBooking(string $relatedModelClass, int $bookingId)
    {
        return Transaction::query()
            ->where('related_type', $relatedModelClass)
            ->where('related_id', $bookingId)
            ->get();
    }

    /**
     * Create a Program WITHOUT triggering the auto-executing-company observer
     * (we pass empty executing_company to satisfy NOT NULL while bypassing
     * via Model::withoutEvents). SQLite-in-test is missing the migration that
     * made executing_company nullable.
     */
    protected function makeProgram(array $overrides = []): \App\Models\Program
    {
        return \App\Models\Program::withoutEvents(function () use ($overrides) {
            return \App\Models\Program::query()->create(array_merge([
                'program_name' => 'برنامج اختبار '.uniqid(),
                'program_type' => 'umrah',
                'executing_company' => '',
                'total_nights' => 10,
                'mecca_hotel_name' => 'فندق مكة',
                'mecca_nights' => 5,
                'medina_hotel_name' => 'فندق المدينة',
                'medina_nights' => 5,
                'airline' => 'مصر للطيران',
                'trip_supervisor' => 'مشرف',
                'accommodation_type' => 'QUAD',
                'default_purchase_price' => 10000,
                'default_selling_price' => 12000,
                'departure_date' => now()->addDays(10)->toDateString(),
                'return_date' => now()->addDays(20)->toDateString(),
                'departure_point' => 'Cairo',
                'is_active' => true,
            ], $overrides));
        });
    }
}
