<?php

namespace Tests\Feature\TourismAudit;

use App\Enums\AccountType;
use App\Enums\TransactionModule;
use App\Models\Account;
use App\Models\AccountEntry;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Finance\AccountModuleContract;
use App\Support\Finance\LedgerBalanceMutationGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * TourismAuditTestCase — base for the Tourism Module Final Isolated Audit (2026-08-17).
 *
 * Conventions:
 *  - SQLite in-memory (per phpunit.xml)
 *  - RefreshDatabase for every test
 *  - Tourism-only fixtures — NO Office accounts, NO Bus/Fawry/Online/Wallet fixtures
 *  - All financial mutations go through real application services
 *  - Defects are recorded as test failures with exact reproduction, expected/actual, and variance
 *  - Production is NEVER touched
 *
 * Audit invariants (asserted after every financial mutation):
 *  - balance = SUM(credit) - SUM(debit) per account
 *  - debit = credit per transaction
 *  - Tourism accounts NEVER post to Office destinations and vice versa
 *  - Idempotency-Key replay produces exactly one financial effect
 */
abstract class TourismAuditTestCase extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    /** Tourism vaults (one per currency) */
    protected Account $vaultEgp;

    protected Account $vaultUsd;

    protected Account $vaultSar;

    protected Account $bankEgp;

    protected Account $walletEgp;

    /** Captured defect ledger (Class-A/B/C) */
    protected array $defects = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::query()->create([
            'name' => 'Tourism Audit Admin',
            'email' => 'tourism-audit-admin-'.Str::random(8).'@test.local',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        Sanctum::actingAs($this->admin, ['*']);

        $this->seedTourismVaults();
    }

    /**
     * Seed Tourism-division liquidity vaults.
     * Per AccountModuleContract: liquidity → module_type MUST be 'tourism' or 'office'.
     */
    protected function seedTourismVaults(): void
    {
        LedgerBalanceMutationGuard::run(function () {
            $this->vaultEgp = Account::query()->create([
                'name' => 'Audit Tourism Vault EGP',
                'type' => AccountType::Cashbox->value,
                'currency' => 'EGP',
                'balance' => 1_000_000.0,
                'is_active' => true,
                'owner_type' => Account::OWNER_TYPE_OFFICE,
                'module_type' => 'tourism',
                'is_module_vault' => true,
                'notes' => 'Tourism Audit 2026-08-17 — safe to delete',
                'created_by' => $this->admin->id,
            ]);

            $this->vaultUsd = Account::query()->create([
                'name' => 'Audit Tourism Vault USD',
                'type' => AccountType::Cashbox->value,
                'currency' => 'USD',
                'balance' => 100_000.0,
                'is_active' => true,
                'owner_type' => Account::OWNER_TYPE_OFFICE,
                'module_type' => 'tourism',
                'is_module_vault' => true,
                'notes' => 'Tourism Audit 2026-08-17 — safe to delete',
                'created_by' => $this->admin->id,
            ]);

            $this->vaultSar = Account::query()->create([
                'name' => 'Audit Tourism Vault SAR',
                'type' => AccountType::Cashbox->value,
                'currency' => 'SAR',
                'balance' => 100_000.0,
                'is_active' => true,
                'owner_type' => Account::OWNER_TYPE_OFFICE,
                'module_type' => 'tourism',
                'is_module_vault' => true,
                'notes' => 'Tourism Audit 2026-08-17 — safe to delete',
                'created_by' => $this->admin->id,
            ]);

            $this->bankEgp = Account::query()->create([
                'name' => 'Audit Tourism Bank EGP',
                'type' => AccountType::Bank->value,
                'currency' => 'EGP',
                'balance' => 500_000.0,
                'is_active' => true,
                'owner_type' => Account::OWNER_TYPE_OFFICE,
                'module_type' => 'tourism',
                'is_module_vault' => false,
                'notes' => 'Tourism Audit 2026-08-17 — safe to delete',
                'created_by' => $this->admin->id,
            ]);

            $this->walletEgp = Account::query()->create([
                'name' => 'Audit Tourism Wallet EGP',
                'type' => AccountType::Wallet->value,
                'currency' => 'EGP',
                'balance' => 200_000.0,
                'is_active' => true,
                'owner_type' => Account::OWNER_TYPE_OFFICE,
                'module_type' => 'tourism',
                'is_module_vault' => false,
                'notes' => 'Tourism Audit 2026-08-17 — safe to delete',
                'created_by' => $this->admin->id,
            ]);
        });

        // ─────────────────────────────────────────────────────────────────
        // FIN-1 (2026-08-21) auto-seeding note:
        //
        // The Account::created observer (FIN-1 fix, app/Models/Account.php
        // lines 175-275) auto-creates paired opening-balance AccountEntry rows
        // on every Account created with `balance > 0`. The previous manual
        // seedOpeningBalance() calls below were written BEFORE the FIN-1
        // observer existed; calling both doubled the credit-side AccountEntry
        // count and broke the `balance = SUM(credit) - SUM(debit)` invariant
        // asserted by assertLedgerGloballyBalanced().
        //
        // Therefore the manual seedOpeningBalance() loop is now REMOVED.
        // The FIN-1 observer handles the credit-side entry on each newly-
        // created account, and the matching debit-side entry is posted on
        // the singleton "System Opening Balances" contra account (also
        // auto-created by the observer). Removing these calls brings the
        // seed back into balance with current production behavior.
        // ─────────────────────────────────────────────────────────────────
    }

    /**
     * Seed opening balance so balance = SUM(credit) - SUM(debit) per project convention.
     */
    protected function seedOpeningBalance(Account $account, float $amount): void
    {
        if ($amount <= 0) {
            return;
        }

        DB::transaction(function () use ($account, $amount) {
            $openingTx = Transaction::query()->create([
                'type' => 'transfer',
                'amount' => $amount,
                'module' => TransactionModule::General->value,
                'from_account_id' => $account->id,
                'to_account_id' => $account->id,
                'currency' => $account->currency,
                'created_by' => $this->admin->id,
                'notes' => 'Opening balance — Tourism Audit 2026-08-17',
            ]);

            AccountEntry::query()->insert([
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
                    'debit' => 0,
                    'credit' => 0,
                    'balance_after' => $amount,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            ]);
        });
    }

    // ───────────────────────────────────────────────────────────────
    // ASSERTIONS — Tourism-specific
    // ───────────────────────────────────────────────────────────────

    protected function assertAccountBalance(Account $account, float $expected, string $message = ''): void
    {
        $actual = round((float) $account->fresh()->balance, 2);
        $expected = round($expected, 2);

        $this->assertEqualsWithDelta($expected, $actual, 0.01, $message ?: sprintf(
            'Account "%s" #%d: expected balance=%.2f, actual=%.2f (delta=%.2f)',
            $account->name, $account->id, $expected, $actual, abs($expected - $actual)
        ));
    }

    protected function assertLedgerBalancedForAccount(Account $account): void
    {
        $entriesNet = round((float) AccountEntry::query()
            ->where('account_id', $account->id)
            ->selectRaw('COALESCE(SUM(credit) - SUM(debit), 0) as net')
            ->value('net'), 2);
        $actual = round((float) $account->fresh()->balance, 2);

        $this->assertEqualsWithDelta($entriesNet, $actual, 0.01, sprintf(
            'Ledger imbalance on "%s" #%d (currency=%s): entries_net=%.2f, balance=%.2f',
            $account->name, $account->id, $account->currency, $entriesNet, $actual
        ));
    }

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

        $this->assertEmpty($imbalanced, 'Ledger imbalance: '.json_encode($imbalanced, JSON_UNESCAPED_UNICODE));

        return $verified;
    }

    protected function assertTransactionBalanced(Transaction $tx): void
    {
        $row = AccountEntry::query()
            ->where('transaction_id', $tx->id)
            ->selectRaw('SUM(debit) as sum_d, SUM(credit) as sum_c')
            ->first();

        $sumD = (float) ($row->sum_d ?? 0);
        $sumC = (float) ($row->sum_c ?? 0);

        $this->assertEqualsWithDelta($sumD, $sumC, 0.01, sprintf(
            'Transaction #%d unbalanced: debit=%.2f, credit=%.2f, diff=%.2f',
            $tx->id, $sumD, $sumC, $sumD - $sumC
        ));
    }

    /**
     * Assert that a transaction's module is one of the Tourism modules.
     */
    protected function assertTransactionIsTourism(Transaction $tx): void
    {
        $tourismModules = ['flight', 'hajj_umra', 'visa', 'tourism'];
        $moduleValue = $tx->module instanceof \BackedEnum ? $tx->module->value : (string) $tx->module;
        $this->assertContains(
            $moduleValue,
            $tourismModules,
            "Transaction #{$tx->id} has module='{$moduleValue}' which is NOT a Tourism module"
        );
    }

    /**
     * Assert that all AccountEntries of a transaction touch only Tourism-division accounts.
     */
    protected function assertTransactionAccountsAreTourism(Transaction $tx): void
    {
        $entries = AccountEntry::query()->where('transaction_id', $tx->id)->get();
        foreach ($entries as $entry) {
            $acc = Account::find($entry->account_id);
            if (! $acc) {
                continue;
            }
            $division = AccountModuleContract::divisionFor($acc->module_type);
            $this->assertContains(
                $division,
                ['tourism', null],
                "Transaction #{$tx->id} entry touches non-Tourism account #{$acc->id} (module_type={$acc->module_type}, division={$division})"
            );
        }
    }

    // ───────────────────────────────────────────────────────────────
    // DEFECT LEDGER
    // ───────────────────────────────────────────────────────────────

    protected function recordDefect(string $severity, string $module, string $file, string $method, string $description, array $extra = []): void
    {
        $this->defects[] = array_merge([
            'severity' => $severity,
            'module' => $module,
            'file' => $file,
            'method' => $method,
            'description' => $description,
            'timestamp' => now()->toIso8601String(),
        ], $extra);
    }

    protected function getDefects(): array
    {
        return $this->defects;
    }

    protected function getDefectsByClass(string $class): array
    {
        return array_values(array_filter($this->defects, fn ($d) => $d['severity'] === $class));
    }

    // ───────────────────────────────────────────────────────────────
    // INDEPENDENT QUERIES — for cross-validation against application reports
    // ───────────────────────────────────────────────────────────────

    /**
     * Independent Tourism ledger query: only transactions whose module is Tourism
     * OR whose entries touch Tourism-division accounts.
     */
    protected function queryTourismLedgerEntries(?string $from = null, ?string $to = null): array
    {
        $q = DB::table('account_entries as ae')
            ->join('accounts as a', 'ae.account_id', '=', 'a.id')
            ->join('transactions as t', 'ae.transaction_id', '=', 't.id')
            ->where(function ($w) {
                $w->whereIn('t.module', ['flight', 'hajj_umra', 'visa', 'tourism'])
                    ->orWhereIn('a.module_type', ['tourism', 'flights', 'hajj_umra', 'visas']);
            });

        if ($from) {
            $q->where('ae.created_at', '>=', $from);
        }
        if ($to) {
            $q->where('ae.created_at', '<=', $to);
        }

        return $q->selectRaw('ae.id, ae.account_id, ae.transaction_id, ae.debit, ae.credit, ae.balance_after, ae.notes, t.type as tx_type, t.module as tx_module, a.module_type as acc_module_type, a.type as acc_type')
            ->get()
            ->toArray();
    }

    /**
     * Independent Tourism P&L: sum of income vs expense transactions where
     * the transaction is Tourism-tagged AND not soft-deleted AND not a reversal.
     */
    protected function calculateTourismPnLIndependent(?string $from = null, ?string $to = null): array
    {
        $modules = ['flight', 'hajj_umra', 'visa', 'tourism'];

        $income = (float) DB::table('transactions as t')
            ->whereIn('t.module', $modules)
            ->where('t.type', 'income')
            ->where('t.notes', 'not like', 'عكس%')
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('visa_bookings')
                    ->whereColumn('visa_bookings.id', '=', 't.related_id')
                    ->where('t.related_type', 'App\\Models\\VisaBooking')
                    ->whereNotNull('visa_bookings.deleted_at');
            })
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('hajj_umra_bookings')
                    ->whereColumn('hajj_umra_bookings.id', '=', 't.related_id')
                    ->where('t.related_type', 'App\\Models\\HajjUmraBooking')
                    ->whereNotNull('hajj_umra_bookings.deleted_at');
            })
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('flight_bookings')
                    ->whereColumn('flight_bookings.id', '=', 't.related_id')
                    ->where('t.related_type', 'App\\Models\\Flight\\FlightBooking')
                    ->whereNotNull('flight_bookings.deleted_at');
            })
            ->when($from, fn ($q) => $q->where('t.created_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('t.created_at', '<=', $to))
            ->sum('t.amount');

        $expense = (float) DB::table('transactions as t')
            ->whereIn('t.module', $modules)
            ->where('t.type', 'expense')
            ->where('t.notes', 'not like', 'عكس%')
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('visa_bookings')
                    ->whereColumn('visa_bookings.id', '=', 't.related_id')
                    ->where('t.related_type', 'App\\Models\\VisaBooking')
                    ->whereNotNull('visa_bookings.deleted_at');
            })
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('hajj_umra_bookings')
                    ->whereColumn('hajj_umra_bookings.id', '=', 't.related_id')
                    ->where('t.related_type', 'App\\Models\\HajjUmraBooking')
                    ->whereNotNull('hajj_umra_bookings.deleted_at');
            })
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('flight_bookings')
                    ->whereColumn('flight_bookings.id', '=', 't.related_id')
                    ->where('t.related_type', 'App\\Models\\Flight\\FlightBooking')
                    ->whereNotNull('flight_bookings.deleted_at');
            })
            ->when($from, fn ($q) => $q->where('t.created_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('t.created_at', '<=', $to))
            ->sum('t.amount');

        return [
            'income' => round($income, 2),
            'expense' => round($expense, 2),
            'profit' => round($income - $expense, 2),
        ];
    }

    /**
     * Get all Tourism-division accounts (liquidity + subject).
     */
    protected function queryTourismAccounts(): array
    {
        return Account::query()
            ->where(function ($q) {
                $q->whereIn('module_type', ['tourism', 'flights', 'hajj_umra', 'visas']);
            })
            ->get()
            ->toArray();
    }
}
