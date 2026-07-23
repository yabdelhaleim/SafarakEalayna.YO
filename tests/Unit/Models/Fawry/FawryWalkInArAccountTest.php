<?php

namespace Tests\Unit\Models\Fawry;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\User;
use App\Services\Finance\LedgerClearingAccounts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

/**
 * Validates the contracted shape of the Fawry walk-in AR account:
 *  - type = Customer (subject mirror — visible in receivables)
 *  - module_type = 'fawry' (specific module, NOT a division)
 *  - is_module_vault = false
 *  - owner_type = OWNER_TYPE_OWNER
 *  - balance = 0 on creation
 *  - currency = EGP
 */
class FawryWalkInArAccountTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Auth::login(User::factory()->create());
    }

    public function test_walk_in_ar_account_is_a_subject_account_not_a_liquidity_vault(): void
    {
        $id = app(LedgerClearingAccounts::class)->fawryWalkInArAccountId();
        $account = Account::findOrFail($id);

        $this->assertSame(AccountType::Customer, $account->type,
            'walk-in AR must be a Subject (Customer) account, not a Cashbox/Bank/Wallet');
        $this->assertSame('fawry', $account->module_type,
            'walk-in AR must bind to specific module fawry, not division office/tourism');
        $this->assertFalse((bool) $account->is_module_vault,
            'walk-in AR must NOT be a unified division vault');
        $this->assertSame(Account::OWNER_TYPE_OWNER, $account->owner_type);
    }

    public function test_walk_in_ar_account_is_idempotent(): void
    {
        $clearing = app(LedgerClearingAccounts::class);

        $firstId = $clearing->fawryWalkInArAccountId();
        $secondId = $clearing->fawryWalkInArAccountId();
        $thirdId = $clearing->fawryWalkInArAccountId();

        $this->assertSame($firstId, $secondId);
        $this->assertSame($secondId, $thirdId);
        $this->assertCount(1, Account::where('name', 'ذمم عملاء فوري غير مسجلين')->get());
    }

    public function test_walk_in_ar_account_starts_at_zero_balance(): void
    {
        $id = app(LedgerClearingAccounts::class)->fawryWalkInArAccountId();
        $this->assertSame(0.0, (float) Account::find($id)->balance);
    }

    public function test_walk_in_ar_account_is_active_and_egp(): void
    {
        $id = app(LedgerClearingAccounts::class)->fawryWalkInArAccountId();
        $account = Account::find($id);

        $this->assertTrue((bool) $account->is_active);
        $this->assertSame('EGP', $account->currency);
    }
}

