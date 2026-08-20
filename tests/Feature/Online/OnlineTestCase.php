<?php

namespace Tests\Feature\Online;

use App\Enums\AccountType;
use App\Enums\CustomerType;
use App\Models\Account;
use App\Models\Customer;
use App\Models\Online\OnlineServiceProvider;
use App\Models\Online\OnlineServiceType;
use App\Models\Setting\PaymentMethod;
use App\Models\User;
use App\Services\Online\OnlineTransactionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

/**
 * Shared base class for Online module PHPUnit tests.
 *
 * Mirrors `tests/Feature/Bus/BusTestCase.php` (the most mature test base
 * in the project). Seeds:
 *  - 1 EGP cashbox (the "online vault" used in every test).
 *  - 1 EGP bank + 1 EGP wallet (so payment-method → account-type routing
 *    can be exercised).
 *  - 1 USD cashbox (so cross-currency rejection can be tested).
 *  - 1 OnlineServiceType + 1 OnlineServiceProvider.
 *  - 1 EGP payment method.
 *  - 1 admin user (auth'd via Sanctum).
 */
class OnlineTestCase extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Account $cashbox;

    protected Account $bank;

    protected Account $wallet;

    protected Account $usdCashbox;

    protected OnlineServiceType $serviceType;

    protected OnlineServiceProvider $provider;

    protected PaymentMethod $cashMethod;

    protected OnlineTransactionService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        Auth::login($this->user);

        $this->cashbox = Account::factory()->active()->create([
            'name' => 'خزينة أونلاين EGP',
            'type' => AccountType::Cashbox,
            'currency' => 'EGP',
            'balance' => 10000,
            'module_type' => 'office',
        ]);

        $this->bank = Account::factory()->active()->create([
            'name' => 'بنك أونلاين EGP',
            'type' => AccountType::Bank,
            'currency' => 'EGP',
            'balance' => 50000,
            'module_type' => 'office',
        ]);

        $this->wallet = Account::factory()->active()->create([
            'name' => 'محفظة أونلاين EGP',
            'type' => AccountType::Wallet,
            'currency' => 'EGP',
            'balance' => 5000,
            'module_type' => 'office',
        ]);

        $this->usdCashbox = Account::factory()->active()->create([
            'name' => 'USD Cashbox',
            'type' => AccountType::Cashbox,
            'currency' => 'USD',
            'balance' => 1000,
            'module_type' => 'office',
        ]);

        $this->serviceType = OnlineServiceType::firstOrCreate(
            ['code' => 'TEST_TYPE'],
            [
                'name_ar' => 'نوع اختبار',
                'name_en' => 'Test type',
                'is_active' => true,
                'order' => 1,
            ],
        );

        $this->provider = OnlineServiceProvider::firstOrCreate(
            ['code' => 'TEST_PROVIDER'],
            [
                'name_ar' => 'مزود اختبار',
                'name_en' => 'Test provider',
                'is_active' => true,
                'order' => 1,
            ],
        );

        $this->cashMethod = PaymentMethod::firstOrCreate(
            ['code' => 'cash'],
            [
                'name_ar' => 'نقدي',
                'name_en' => 'Cash',
                'is_active' => true,
                'order' => 1,
            ],
        );

        $this->service = app(OnlineTransactionService::class);
    }

    /**
     * Create a Customer for tests that need a registered client.
     */
    protected function makeCustomer(string $name = 'عميل اختبار', ?string $phone = null): Customer
    {
        return Customer::create([
            'full_name' => $name,
            'phone' => $phone ?? '0100'.random_int(1000000, 9999999),
            'type' => CustomerType::Individual->value,
            'module_type' => 'online',
            'status' => 'active',
            'created_by' => $this->user->id,
        ]);
    }

    /**
     * Sum of credit and debit on a given account (for ledger balance
     * assertions). Per the project convention balance = SUM(credit) - SUM(debit).
     */
    protected function glBalance(int $accountId): float
    {
        $row = \DB::table('account_entries')
            ->where('account_id', $accountId)
            ->selectRaw('COALESCE(SUM(credit), 0) - COALESCE(SUM(debit), 0) as net')
            ->value('net');

        return (float) $row;
    }

    /**
     * Account.balance (the cached column).
     */
    protected function accountBalance(int $accountId): float
    {
        return (float) Account::find($accountId)?->balance ?? 0.0;
    }

    /**
     * Asserts the project's GL invariant: cached balance DELTA == GL net
     * DELTA, where the delta is measured against the GL state at the first
     * assertion. This keeps the assertion correct for accounts that start
     * with a non-zero cached balance (e.g. the seeded cashbox with 10000).
     */
    protected function assertLedgerBalancedForAccount(int $accountId): void
    {
        $cached = $this->accountBalance($accountId);
        $gl = $this->glBalance($accountId);
        if (! array_key_exists($accountId, $this->glBaselines)) {
            $this->glBaselines[$accountId] = [
                'cached' => $cached,
                'gl' => $gl,
            ];
        }
        $baseline = $this->glBaselines[$accountId];
        $cachedDelta = $cached - $baseline['cached'];
        $glDelta = $gl - $baseline['gl'];
        $this->assertEqualsWithDelta(
            $cachedDelta,
            $glDelta,
            0.01,
            "Account #{$accountId} cached delta ({$cachedDelta}) disagrees with GL delta ({$glDelta}).",
        );
    }

    /**
     * Per-account (cached, gl) baselines recorded at the first assertion.
     */
    protected array $glBaselines = [];

    /**
     * Asserts that every Online transaction is balanced (debit == credit per
     * transaction_id). This is the project-wide double-entry invariant.
     */
    protected function assertOnlineLedgerBalanced(): void
    {
        $imbalanced = \DB::table('account_entries as ae')
            ->join('transactions as t', 'ae.transaction_id', '=', 't.id')
            ->where('t.module', 'online')
            ->groupBy('t.id')
            ->selectRaw('t.id, COALESCE(SUM(ae.credit), 0) - COALESCE(SUM(ae.debit), 0) as net')
            ->havingRaw('ABS(net) > 0.01')
            ->get();

        $this->assertCount(
            0,
            $imbalanced,
            'Found imbalanced Online transactions: '
            .$imbalanced->pluck('id')->implode(', '),
        );
    }
}
