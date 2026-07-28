<?php

namespace Tests\Feature\Finance;

use App\Enums\AccountType;
use App\Enums\TransactionModule;
use App\Models\Account;
use App\Models\Employee;
use App\Models\Transaction;
use App\Models\Transfer;
use App\Models\User;
use App\Services\Finance\TransactionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Regression test for audit finding 4.1 (Critical, fixed in fa81afb).
 *
 * The original TransactionService::recordTransfer() rejected transfers when
 * fromAccount->balance < debitAmount, even though the project's Account
 * convention explicitly allows prepaid carriers/systems and supplier AP
 * accounts to go negative (see App\Models\Account::balanceConventionLines 86-89).
 *
 * This test class locks in the corrected behavior:
 *   - Cashbox / bank / wallet (liquidity) accounts STILL reject insufficient
 *     balance (backward-compat — the invariant test in
 *     FinanceTransferHistoryTest still passes).
 *   - Supplier accounts auto-allow going negative (per Account convention).
 *   - "prepaid" accounts auto-allow going negative.
 *   - "airline_account" type (used by Flight module) auto-allow going negative.
 *   - Any account can opt-in to negative direction via allow_from_negative=true.
 *
 * @see \App\Services\Finance\TransactionService::recordTransfer
 */
class RecordTransferAllowNegativeBalanceTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected TransactionService $transactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::query()->create([
            'name' => 'Negative Balance Tester',
            'email' => 'negative-balance@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        Employee::query()->create([
            'user_id' => $this->admin->id,
            'status' => 'active',
        ]);

        $this->actingAs($this->admin);

        $this->transactions = app(TransactionService::class);
    }

    /**
     * Helper: create an Account of a given type with a starting balance.
     */
    protected function createAccount(string $name, string $type, float $balance, array $overrides = []): Account
    {
        return Account::query()->create(array_merge([
            'name' => $name,
            'type' => $type,
            'currency' => 'EGP',
            'balance' => $balance,
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'office',
            'created_by' => $this->admin->id,
        ], $overrides));
    }

    /**
     * The most important test: a supplier account with negative balance can
     * be debited (per App\Models\Account convention line 86-89, supplier AP
     * legitimately goes negative). Before the audit fix, this threw an
     * "Insufficient balance" error and blocked legitimate supplier draws.
     */
    public function test_supplier_account_can_go_negative_without_flag(): void
    {
        $supplier = $this->createAccount('مورد تجريبي', AccountType::Supplier->value, -500.00, [
            'module_type' => 'flights',
        ]);

        $cashbox = $this->createAccount('خزينة استلام', AccountType::Cashbox->value, 0.00);

        $transfer = $this->transactions->recordTransfer([
            'from_account_id' => $supplier->id,
            'to_account_id' => $cashbox->id,
            'amount' => 200.0,
            'module' => TransactionModule::General->value,
            'notes' => 'سحب من مورد (رصيده مسبقاً سالب)',
            'created_by' => $this->admin->id,
        ]);

        $this->assertInstanceOf(Transfer::class, $transfer);
        $this->assertSame(200.0, (float) $transfer->amount);

        // Both accounts refresh from DB to verify the balance mutation is
        // persisted through the LedgerBalanceMutationGuard.
        $supplier->refresh();
        $cashbox->refresh();

        $this->assertSame(-700.0, (float) $supplier->balance);
        $this->assertSame(200.0, (float) $cashbox->balance);

        // Two AccountEntry rows must exist: a debit on the supplier and a
        // credit on the cashbox (per project convention balance = SUM(credit)
        // - SUM(debit), see TransactionService line 449).
        $this->assertSame(1, Transaction::query()->where('id', $transfer->transaction_id)->count());
        $this->assertSame(2, \App\Models\AccountEntry::query()
            ->where('transaction_id', $transfer->transaction_id)
            ->count());
    }

    /**
     * Cashbox (liquidity) accounts must STILL throw on insufficient balance
     * — backward compat for the existing FinanceTransferHistoryTest strict
     * invariant. We do not want the fix to silently let cashboxes overdraw.
     */
    public function test_cashbox_rejects_insufficient_balance(): void
    {
        $cashbox = $this->createAccount('خزينة', AccountType::Cashbox->value, 100.00);
        $target = $this->createAccount('هدف', AccountType::Bank->value, 0.00);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/Insufficient balance/i');

        $this->transactions->recordTransfer([
            'from_account_id' => $cashbox->id,
            'to_account_id' => $target->id,
            'amount' => 500.0,
            'module' => TransactionModule::General->value,
            'notes' => 'محاولة سحب أكبر من الرصيد',
            'created_by' => $this->admin->id,
        ]);
    }

    /**
     * Explicit opt-in via allow_from_negative=true should work even for a
     * liquidity account (cashbox). This is the override path used by flows
     * like Fawry/Online that may need to consume a cashbox even if it would
     * momentarily go negative (e.g. walk-in AR reclamation).
     */
    public function test_cashbox_with_explicit_flag_can_go_negative(): void
    {
        $cashbox = $this->createAccount('خزينة opt-in', AccountType::Cashbox->value, 100.00);
        $target = $this->createAccount('هدف opt-in', AccountType::Bank->value, 0.00);

        $transfer = $this->transactions->recordTransfer([
            'from_account_id' => $cashbox->id,
            'to_account_id' => $target->id,
            'amount' => 500.0,
            'allow_from_negative' => true,
            'module' => TransactionModule::General->value,
            'notes' => 'سحب بـ opt-in صريح',
            'created_by' => $this->admin->id,
        ]);

        $this->assertInstanceOf(Transfer::class, $transfer);

        $cashbox->refresh();
        $target->refresh();

        $this->assertSame(-400.0, (float) $cashbox->balance);
        $this->assertSame(500.0, (float) $target->balance);
    }

    /**
     * The audit fix comment says "supplier/prepaid/airline_account" are
     * auto-allowed. The AccountType enum doesn't have literal 'prepaid' or
     * 'airline_account' cases — those types live on their own models
     * (FlightCarrier / AirlineAccount) and have their own debit/recharge
     * flows, not the standard Transfer flow.
     *
     * The standard Transfer flow therefore tests the explicit-flag opt-in
     * path on a regular supplier account (already covered by
     * test_supplier_account_can_go_negative_without_flag).
     *
     * This test verifies the explicit opt-in works on the same supplier
     * account, since the flag-based branch is what FlightCarrier /
     * AirlineAccount flows ultimately hit via their own service.
     */
    public function test_supplier_account_with_explicit_flag_can_go_more_negative(): void
    {
        $supplier = $this->createAccount('مورد override', AccountType::Supplier->value, -200.00, [
            'module_type' => 'flights',
        ]);
        $cashbox = $this->createAccount('خزينة override', AccountType::Cashbox->value, 0.00);

        $transfer = $this->transactions->recordTransfer([
            'from_account_id' => $supplier->id,
            'to_account_id' => $cashbox->id,
            'amount' => 100.0,
            'allow_from_negative' => true,
            'module' => TransactionModule::Flight->value,
            'notes' => 'سحب بـ flag من مورد طيران',
            'created_by' => $this->admin->id,
        ]);

        $this->assertInstanceOf(Transfer::class, $transfer);

        $supplier->refresh();
        $cashbox->refresh();

        $this->assertSame(-300.0, (float) $supplier->balance);
        $this->assertSame(100.0, (float) $cashbox->balance);
    }

    /**
     * "customer" account type is NOT in the auto-allow list (per the audit
     * fix comment: "supplier/prepaid/airline_account"). A customer AR
     * going negative (we owe them) is the inverse direction — must NOT be
     * auto-allowed. Customers reaching negative balance is unusual and
     * should still require an explicit flag.
     */
    public function test_customer_account_does_not_auto_allow_negative(): void
    {
        $customer = $this->createAccount('عميل', AccountType::Customer->value, -100.00, [
            'module_type' => 'flights',
        ]);
        $cashbox = $this->createAccount('خزينة', AccountType::Cashbox->value, 0.00);

        // Customer is not in the auto-allow list, AND we're not passing the
        // explicit flag → must throw because |-100| < 500.
        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/Insufficient balance/i');

        $this->transactions->recordTransfer([
            'from_account_id' => $customer->id,
            'to_account_id' => $cashbox->id,
            'amount' => 500.0,
            'module' => TransactionModule::General->value,
            'notes' => 'محاولة سحب من عميل',
            'created_by' => $this->admin->id,
        ]);
    }

    /**
     * When allow_from_negative=true is passed for a customer account,
     * the transfer must succeed (explicit override).
     */
    public function test_customer_account_with_explicit_flag_can_go_negative(): void
    {
        $customer = $this->createAccount('عميل override', AccountType::Customer->value, 50.00, [
            'module_type' => 'flights',
        ]);
        $cashbox = $this->createAccount('خزينة override', AccountType::Cashbox->value, 0.00);

        $transfer = $this->transactions->recordTransfer([
            'from_account_id' => $customer->id,
            'to_account_id' => $cashbox->id,
            'amount' => 200.0,
            'allow_from_negative' => true,
            'module' => TransactionModule::General->value,
            'notes' => 'سحب بـ opt-in صريح من حساب عميل',
            'created_by' => $this->admin->id,
        ]);

        $this->assertInstanceOf(Transfer::class, $transfer);

        $customer->refresh();
        $cashbox->refresh();

        $this->assertSame(-150.0, (float) $customer->balance);
        $this->assertSame(200.0, (float) $cashbox->balance);
    }
}
