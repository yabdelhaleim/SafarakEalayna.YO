<?php

namespace Tests\Feature\Finance;

use App\Enums\AccountType;
use App\Enums\TransactionModule;
use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\AccountEntry;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Finance\LedgerRepairService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class LedgerRepairTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::query()->create([
            'name' => 'Ledger Repair Tester',
            'email' => 'ledger-repair-test@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);
    }

    public function test_backfill_adds_contra_leg_to_legacy_income(): void
    {
        $clearing = Account::query()->create([
            'name' => 'إقفال مبيعات الطيران (نظام)',
            'type' => AccountType::Cashbox,
            'balance' => 0,
            'currency' => 'EGP',
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'office',
        ]);

        config(['accounting.clearing.income.flight' => $clearing->name]);

        $cashbox = Account::query()->create([
            'name' => 'Test Cashbox',
            'type' => AccountType::Cashbox,
            'balance' => 500,
            'currency' => 'EGP',
            'is_active' => true,
            'owner_type' => 'office',
        ]);

        $tx = Transaction::query()->create([
            'type' => TransactionType::Income,
            'amount' => 500,
            'module' => TransactionModule::Flight,
            'to_account_id' => $cashbox->id,
            'created_by' => $this->user->id,
            'notes' => 'legacy',
        ]);

        AccountEntry::query()->create([
            'account_id' => $cashbox->id,
            'transaction_id' => $tx->id,
            'debit' => 0,
            'credit' => 500,
            'balance_after' => 500,
        ]);

        $repair = app(LedgerRepairService::class);
        $result = $repair->backfillLegacySingleLegPostings();

        $this->assertSame(1, $result['backfilled']);
        $this->assertSame(2, AccountEntry::where('transaction_id', $tx->id)->count());

        $sums = AccountEntry::query()
            ->where('transaction_id', $tx->id)
            ->selectRaw('SUM(debit) as d, SUM(credit) as c')
            ->first();

        $this->assertEqualsWithDelta(500.0, (float) $sums->d, 0.01);
        $this->assertEqualsWithDelta(500.0, (float) $sums->c, 0.01);
    }

    public function test_sync_customer_zeros_phantom_balance_without_entries(): void
    {
        $customer = Account::query()->create([
            'name' => 'حساب العميل: Phantom',
            'type' => AccountType::Customer,
            'balance' => 9999,
            'currency' => 'EGP',
            'is_active' => true,
            'owner_type' => 'owner',
            'module_type' => 'tourism',
        ]);

        $repair = app(LedgerRepairService::class);
        $result = $repair->syncCustomerBalancesFromLedger($this->user->id);

        $this->assertSame(1, $result['zeroed']);
        $this->assertSame(0.0, (float) $customer->fresh()->balance);
    }
}
