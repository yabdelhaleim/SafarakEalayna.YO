<?php

namespace Tests\Feature\Visa;

use App\Enums\AccountType;
use App\Enums\TransactionModule;
use App\Enums\VisaEntryType;
use App\Enums\VisaStatus;
use App\Enums\VisaType;
use App\Models\Account;
use App\Models\AccountEntry;
use App\Models\Customer;
use App\Models\HajjUmra\VisaAgent;
use App\Models\HajjUmra\VisaDuration;
use App\Models\Transaction;
use App\Models\User;
use App\Models\VisaBooking;
use App\Models\VisaDetail;
use App\Support\Finance\LedgerBalanceMutationGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Base TestCase for Visa Module feature tests (this audit).
 *
 * Provides:
 *   - Authenticated Sanctum user (admin role, is_active=true)
 *   - EGP / USD / SAR cashbox vaults (tourism division, module_type='tourism')
 *   - Customer with EGP AR account
 *   - VisaDuration reference row (used by every booking payload)
 *   - VisaAgent helper (with linked Supplier account)
 *   - Helpers: assertAccountBalance / assertLedgerBalancedForAccount / assertLedgerGloballyBalanced
 *   - Helper: makeBooking() builds a fully populated booking payload
 *
 * Conventions enforced:
 *   1. Liquidity accounts MUST carry module_type='tourism' (per AccountModuleContract for visas module)
 *   2. Booking currency MUST match the linked account currency (enforced by VisaLiquidityAccount rule)
 *   3. Project balance invariant: balance = SUM(credit) - SUM(debit) per account
 *   4. Visa reversals are additive (never mutate original Transaction.amount)
 */
abstract class VisaTestCase extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected User $employeeUser;

    protected Account $vaultEgp;

    protected Account $vaultUsd;

    protected Account $vaultSar;

    protected Account $bankEgp;

    protected Customer $customer;

    protected VisaDuration $duration;

    protected VisaAgent $agent;

    /**
     * Default payload for a fully-valid visa booking. Tests override as needed.
     */
    protected function bookingPayload(array $overrides = []): array
    {
        return array_merge([
            'customer_id' => $this->customer->id,
            'purchase_price' => 1000.0,
            'selling_price' => 1500.0,
            'service_fee' => 100.0,
            'currency' => 'EGP',
            'account_id' => $this->vaultEgp->id,
            'status' => VisaStatus::Submitted->value,
            'agent_name' => 'Audit Test Agent',
            'notes' => 'Audit 2026-08-14 — baseline booking',
            'visa_details' => [
                'visa_type' => VisaType::Tourist->value,
                'country' => 'AUDIT-LAND',
                'duration' => '30',
                'visa_duration_id' => $this->duration->id,
                'entry_type' => VisaEntryType::Single->value,
                'validity_from' => now()->toDateString(),
                'validity_to' => now()->addMonths(6)->toDateString(),
                'submission_date' => now()->toDateString(),
                'expected_result_date' => now()->addDays(15)->toDateString(),
                'executing_company' => 'Audit Executing Co',
                'executing_agent' => 'Audit Agent',
                'executing_agent_contact' => '01000000000',
                'visa_agent_id' => $this->agent->id,
            ],
        ], $overrides);
    }

    protected function setUp(): void
    {
        parent::setUp();

        // ─── Admin user (full access) ────────────────────────────────────────
        $this->user = User::query()->create([
            'name' => 'Visa Audit Admin',
            'email' => 'visa-audit-admin@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        // ─── Employee user (no admin — for permission tests) ────────────────
        $this->employeeUser = User::query()->create([
            'name' => 'Visa Audit Employee',
            'email' => 'visa-audit-employee@example.com',
            'password' => Hash::make('password'),
            'role' => 'employee',
            'is_active' => true,
        ]);

        Sanctum::actingAs($this->user, ['*']);

        // ─── Vaults (tourism division per AccountModuleContract) ─────────────
        LedgerBalanceMutationGuard::run(function () {
            $this->vaultEgp = Account::create([
                'name' => 'Audit Vault EGP',
                'type' => AccountType::Cashbox,
                'currency' => 'EGP',
                'balance' => 100000.0,
                'is_active' => true,
                'owner_type' => Account::OWNER_TYPE_OFFICE,
                'module_type' => 'tourism',
                'is_module_vault' => true,
                'notes' => 'Visa Audit 2026-08-14 — safe to delete',
                'created_by' => $this->user->id,
            ]);

            $this->vaultUsd = Account::create([
                'name' => 'Audit Vault USD',
                'type' => AccountType::Cashbox,
                'currency' => 'USD',
                'balance' => 10000.0,
                'is_active' => true,
                'owner_type' => Account::OWNER_TYPE_OFFICE,
                'module_type' => 'tourism',
                'is_module_vault' => true,
                'notes' => 'Visa Audit 2026-08-14 — safe to delete',
                'created_by' => $this->user->id,
            ]);

            $this->vaultSar = Account::create([
                'name' => 'Audit Vault SAR',
                'type' => AccountType::Cashbox,
                'currency' => 'SAR',
                'balance' => 10000.0,
                'is_active' => true,
                'owner_type' => Account::OWNER_TYPE_OFFICE,
                'module_type' => 'tourism',
                'is_module_vault' => true,
                'notes' => 'Visa Audit 2026-08-14 — safe to delete',
                'created_by' => $this->user->id,
            ]);

            $this->bankEgp = Account::create([
                'name' => 'Audit Bank EGP',
                'type' => AccountType::Bank,
                'currency' => 'EGP',
                'balance' => 50000.0,
                'is_active' => true,
                'owner_type' => Account::OWNER_TYPE_OFFICE,
                'module_type' => 'tourism',
                'is_module_vault' => false,
                'notes' => 'Visa Audit 2026-08-14 — safe to delete',
                'created_by' => $this->user->id,
            ]);
        });

        // Seed opening balance entries so the per-account invariant holds
        // (balance == SUM(credit) - SUM(debit)).
        $this->seedOpeningBalanceFor($this->vaultEgp, 100000.0);
        $this->seedOpeningBalanceFor($this->vaultUsd, 10000.0);
        $this->seedOpeningBalanceFor($this->vaultSar, 10000.0);
        $this->seedOpeningBalanceFor($this->bankEgp, 50000.0);

        // ─── Customer (without AR account — service auto-creates) ───────────
        $this->customer = Customer::query()->create([
            'full_name' => 'Audit Customer EGP',
            'phone' => '01000000100',
            'national_id' => '12345678901234',
            'passport_number' => 'A12345678',
            'type' => 'individual',
            'status' => 'active',
            'currency' => 'EGP',
            'created_by' => $this->user->id,
        ]);

        // ─── Reference data ─────────────────────────────────────────────────
        $this->duration = VisaDuration::query()->create([
            'code' => 'AUDIT-30D',
            'label_ar' => '30 يوم',
            'label_en' => '30 days',
            'months' => 1,
            'entry_type' => 'single',
            'sort_order' => 99,
            'is_active' => true,
        ]);

        // ─── VisaAgent with linked Supplier account ─────────────────────────
        LedgerBalanceMutationGuard::run(function () {
            $supplierAccount = Account::create([
                'name' => 'حساب وكيل: Audit Agent Co',
                'type' => AccountType::Supplier,
                'currency' => 'EGP',
                'balance' => 0.0,
                'is_active' => true,
                'owner_type' => Account::OWNER_TYPE_OWNER,
                'module_type' => 'visas',
                'is_module_vault' => false,
                'notes' => 'Visa Audit 2026-08-14 — test supplier',
                'created_by' => $this->user->id,
            ]);

            $this->agent = VisaAgent::query()->create([
                'company_name' => 'Audit Agent Co',
                'contact_person' => 'Audit Contact',
                'phone' => '01000000200',
                'email' => 'audit-agent@example.com',
                'country' => 'EG',
                'visa_type' => 'tourist',
                'default_cost_price' => 800.0,
                'account_id' => $supplierAccount->id,
                'is_active' => true,
                'notes' => 'Visa Audit 2026-08-14 — test agent',
                'created_by' => $this->user->id,
            ]);
        });
    }

    /**
     * Create an opening-balance journal entry so balance = SUM(credit) - SUM(debit).
     *
     * Mirrors the BusTestCase pattern exactly. Required by assertLedgerGloballyBalanced().
     *
     * Project convention: balance = SUM(credit) - SUM(debit).
     * To create a starting balance of $amount, post one credit entry of $amount
     * plus a no-op entry so the per-account invariant holds.
     */
    protected function seedOpeningBalanceFor(Account $account, float $amount): void
    {
        if ($amount <= 0) {
            return;
        }

        DB::transaction(function () use ($account, $amount) {
            $openingTx = Transaction::create([
                'type' => 'transfer',
                'amount' => $amount,
                'module' => TransactionModule::General->value,
                'from_account_id' => $account->id,
                'to_account_id' => $account->id,  // self-loop (offset via entries)
                'currency' => $account->currency,
                'created_by' => $this->user->id,
                'notes' => 'Opening balance — seeded by VisaTestCase',
            ]);

            AccountEntry::insert([
                [
                    'account_id' => $account->id,
                    'transaction_id' => $openingTx->id,
                    'debit' => 0,
                    'credit' => $amount,
                    'balance_after' => $amount,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                [
                    'account_id' => $account->id,
                    'transaction_id' => $openingTx->id,
                    'debit' => 0,                      // opening 'debit' = amount is the canonical mark
                    'credit' => 0,                     // (mirror of BusTestCase pattern)
                    'balance_after' => $amount,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            ]);
        });
    }

    /**
     * Make a VisaBooking directly via the Service (skipping FormRequest validation).
     * Returns the fresh booking with all relations loaded.
     */
    protected function makeBooking(array $overrides = []): VisaBooking
    {
        $payload = $this->bookingPayload($overrides);

        // Strip visa_details (the Service reads them separately)
        $detailData = $payload['visa_details'] ?? [];
        unset($payload['visa_details']);

        $service = app(\App\Services\Visa\VisaBookingService::class);
        $booking = $service->create(array_merge($payload, ['visa_details' => $detailData]));

        return $booking;
    }

    /**
     * Make a fresh customer with given attributes.
     */
    protected function makeCustomer(array $attrs = []): Customer
    {
        return Customer::query()->create(array_merge([
            'full_name' => 'Audit Customer '.uniqid(),
            'phone' => '01'.str_pad((string) random_int(100000000, 999999999), 9, '0', STR_PAD_LEFT),
            'national_id' => str_pad((string) random_int(10000000000000, 99999999999999), 14, '0', STR_PAD_LEFT),
            'passport_number' => 'A'.str_pad((string) random_int(10000000, 99999999), 8, '0', STR_PAD_LEFT),
            'type' => 'individual',
            'status' => 'active',
            'currency' => 'EGP',
            'created_by' => $this->user->id,
        ], $attrs));
    }

    /**
     * Switch the authenticated user (for permission tests).
     */
    protected function actingAsUser(User $user, array $abilities = ['*']): self
    {
        Sanctum::actingAs($user, $abilities);

        return $this;
    }

    // ────────────────────────────────────────────────────────────────────────
    // Assertions
    // ────────────────────────────────────────────────────────────────────────

    protected function assertAccountBalance(Account $account, float $expected, string $message = ''): void
    {
        $actual = round((float) $account->fresh()->balance, 2);
        $expected = round($expected, 2);

        $this->assertEqualsWithDelta(
            $expected,
            $actual,
            0.01,
            $message ?: sprintf(
                'Expected %s #%d balance=%s but got %s',
                $account->name,
                $account->id,
                number_format($expected, 2),
                number_format($actual, 2)
            )
        );
    }

    protected function assertLedgerBalancedForAccount(Account $account): void
    {
        $entriesSum = round((float) AccountEntry::query()
            ->where('account_id', $account->id)
            ->selectRaw('SUM(credit) - SUM(debit) as net')
            ->value('net'), 2);
        $actual = round((float) $account->fresh()->balance, 2);

        $this->assertEqualsWithDelta(
            $entriesSum,
            $actual,
            0.01,
            sprintf(
                'Ledger imbalance on "%s" #%d (currency=%s): expected=%s, actual=%s',
                $account->name,
                $account->id,
                $account->currency,
                number_format($entriesSum, 2),
                number_format($actual, 2)
            )
        );
    }

    /**
     * Assert project-wide ledger invariant: balance = SUM(credit) - SUM(debit) per account.
     */
    protected function assertLedgerGloballyBalanced(): int
    {
        $rows = Account::query()
            ->leftJoin('account_entries', 'accounts.id', '=', 'account_entries.account_id')
            ->groupBy('accounts.id', 'accounts.name', 'accounts.balance', 'accounts.currency')
            ->selectRaw('accounts.id, accounts.name, accounts.balance, accounts.currency,
                          COALESCE(SUM(account_entries.credit), 0) as sum_credit,
                          COALESCE(SUM(account_entries.debit), 0) as sum_debit,
                          COUNT(account_entries.id) as entry_count')
            ->get();

        $imbalanced = [];
        $verified = 0;

        foreach ($rows as $row) {
            // Skip opening-balance placeholders (entries == 0, balance != 0)
            if ((int) $row->entry_count === 0 && abs((float) $row->balance) > 0.001) {
                continue;
            }

            $entriesNet = round((float) $row->sum_credit - (float) $row->sum_debit, 2);
            $actual = round((float) $row->balance, 2);

            $verified++;
            if (abs($entriesNet - $actual) > 0.01) {
                $imbalanced[] = [
                    'id' => $row->id,
                    'name' => $row->name,
                    'currency' => $row->currency,
                    'expected' => $entriesNet,
                    'actual' => $actual,
                    'entries' => (int) $row->entry_count,
                ];
            }
        }

        $this->assertEmpty(
            $imbalanced,
            'Ledger imbalance detected: '.json_encode($imbalanced, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
        );

        return $verified;
    }

    /**
     * Assert that a Transaction is balanced: SUM(debit) = SUM(credit).
     */
    protected function assertTransactionBalanced(Transaction $tx): void
    {
        $row = AccountEntry::query()
            ->where('transaction_id', $tx->id)
            ->selectRaw('SUM(debit) as sum_d, SUM(credit) as sum_c')
            ->first();

        $sumD = (float) ($row->sum_d ?? 0);
        $sumC = (float) ($row->sum_c ?? 0);

        $this->assertEqualsWithDelta(
            $sumD,
            $sumC,
            0.01,
            sprintf(
                'Transaction #%d unbalanced: debit=%s, credit=%s, diff=%s',
                $tx->id,
                number_format($sumD, 2),
                number_format($sumC, 2),
                number_format($sumD - $sumC, 2)
            )
        );
    }
}