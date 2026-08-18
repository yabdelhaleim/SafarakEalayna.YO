<?php

declare(strict_types=1);

namespace Tests\Stress\Support;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\AccountEntry;
use App\Models\Customer;
use App\Models\Supplier;
use App\Models\Transaction;
use App\Support\Finance\AccountModuleContract;
use App\Support\Finance\LedgerBalanceMutationGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * StressBulkFactory — chunked helpers for high-volume stress seeding.
 *
 * Phase 25 contract:
 *  - Reuses existing Eloquent factories (AccountFactory, CustomerFactory,
 *    UserFactory) WITHOUT modifying them.
 *  - Uses raw DB::table()->insert() in 1000-row chunks for the high-volume
 *    rows (customers, suppliers) where factory->count() is too slow.
 *  - Overrides only the fields the schema needs (e.g. customers.module_type)
 *    by passing overrides to the factory / insert arrays.
 *  - Respects all DB constraints:
 *      * customers (phone, national_id) is composite UNIQUE
 *      * suppliers.code is UNIQUE
 *      * accounts.type must be a valid AccountType enum case
 *      * liquidity accounts require module_type ∈ {office, tourism}
 *      * subject accounts require module_type ∈ {bus, fawry, online,
 *        wallet_transfer, flights, hajj_umra, visas}
 *  - Performs every balance mutation inside LedgerBalanceMutationGuard so
 *    the Account::updating boot guard does not fire.
 *
 * IMPORTANT: This class never modifies CustomerFactory, AccountFactory,
 * UserFactory, SupplierFactory (none), or any migration/model/config.
 * It is the ONLY place where the stress dataset is built.
 */
final class StressBulkFactory
{
    public const CHUNK_SIZE = 1000;

    /** Office-division modules that a Customer subject account can carry. */
    public const CUSTOMER_OFFICE_MODULES = ['bus', 'fawry', 'online', 'wallet_transfer'];

    /** Tourism-division modules that a Customer subject account can carry. */
    public const CUSTOMER_TOURISM_MODULES = ['flights', 'hajj_umra', 'visas'];

    /** Valid supplier.type values (from migration 2026_04_28_001447). */
    public const SUPPLIER_TYPES = [
        'airline', 'bus_company', 'hotel', 'visa_provider',
        'service_provider', 'other',
    ];

    /**
     * Bulk-create $count customers using CustomerFactory + chunked insert
     * where appropriate. Each customer gets a unique (phone, national_id)
     * pair to satisfy the composite UNIQUE constraint.
     *
     * @param  string  $moduleType  valid customer module (e.g. 'bus', 'flights')
     * @return array{customers:int, accounts:int}
     */
    public static function bulkCustomers(int $count, string $moduleType = 'bus'): array
    {
        if (!in_array($moduleType, array_merge(self::CUSTOMER_OFFICE_MODULES, self::CUSTOMER_TOURISM_MODULES), true)) {
            throw new \InvalidArgumentException(
                "Invalid customer module_type '{$moduleType}'. "
                ."Use one of: ".implode(', ', array_merge(self::CUSTOMER_OFFICE_MODULES, self::CUSTOMER_TOURISM_MODULES))
            );
        }

        // Phase B re-runs must not collide with already-existing rows.
        // Compute the next free sequence from the DB so the seeder is idempotent.
        $maxSeq = (int) (DB::table('customers')
            ->where('phone', 'LIKE', '+201%')
            ->where('national_id', 'LIKE', 'STR%')
            ->selectRaw("COALESCE(MAX(CAST(SUBSTRING(phone FROM 5) AS UNSIGNED)), 0) AS max_seq")
            ->value('max_seq'));

        $created = 0;
        $lastError = null;
        foreach (self::chunks($count, 200) as $range) {
            for ($i = 0; $i < $range['size']; $i++) {
                $seq = $maxSeq + $range['offset'] + $i + 1;
                try {
                    Customer::factory()->create([
                        // customers.phone is varchar(255) — +201 + 9 digits = 13 chars
                        'phone'        => sprintf('+201%09d', $seq),
                        // customers.national_id is varchar(14) — STR + 11 digits = 14 chars exactly
                        'national_id'  => sprintf('STR%011d', $seq),
                        'module_type'  => $moduleType,
                        'created_by'   => null,
                    ]);
                    $created++;
                } catch (\Throwable $e) {
                    // Record the first error and STOP — the seeder must not silently
                    // produce a partial dataset that passes the gate while being
                    // materially wrong.
                    $lastError = $e->getMessage();
                    break 2;
                }
            }
        }
        if ($lastError) {
            throw new \RuntimeException(
                "bulkCustomers aborted at sequence {$created}: {$lastError}"
            );
        }
        return ['customers' => $created, 'accounts' => 0];
    }

    /**
     * Bulk-create $count suppliers with unique sequential codes.
     *
     * Schema (migration 2026_04_28_001447):
     *  - name: required, string
     *  - code: required, string, UNIQUE
     *  - type: enum (default 'other')
     *  - account_id: nullable FK to accounts
     *  - credit_limit: decimal default 0
     *  - current_debt: decimal default 0
     *  - payment_terms: enum (default 'cash')
     *  - is_active: bool default true
     *  - created_by: nullable FK to users
     */
    public static function bulkSuppliers(int $count, ?int $actorId = null): array
    {
        $faker = app(\Faker\Generator::class);
        $created = 0;

        // Phase B re-runs must not collide with existing supplier codes.
        $maxSeq = (int) (DB::table('suppliers')
            ->where('code', 'LIKE', 'STRSUP-%')
            ->selectRaw("COALESCE(MAX(CAST(SUBSTRING(code FROM 8) AS UNSIGNED)), 0) AS max_seq")
            ->value('max_seq'));

        foreach (self::chunks($count) as $range) {
            $rows = [];
            for ($i = 0; $i < $range['size']; $i++) {
                $seq = $maxSeq + $range['offset'] + $i + 1;
                $rows[] = [
                    'name'         => 'STRESS-SUP-'.$faker->company().' #'.$seq,
                    'code'         => sprintf('STRSUP-%08d', $seq),
                    'type'         => $faker->randomElement(self::SUPPLIER_TYPES),
                    'contact_person'=> $faker->name(),
                    'email'        => 'stress-sup-'.$seq.'@safarakealayna.test',
                    'phone'        => $faker->phoneNumber(),
                    'credit_limit' => $faker->randomFloat(2, 10000, 100000),
                    'current_debt' => 0,
                    'payment_terms'=> 'cash',
                    'is_active'    => true,
                    'created_by'   => $actorId,
                    'created_at'   => now(),
                    'updated_at'   => now(),
                ];
            }
            DB::table('suppliers')->insert($rows);
            $created += count($rows);
        }
        return ['suppliers' => $created];
    }

    /**
     * Bulk-create $count liquidity accounts (cashbox / bank / wallet) using
     * the existing AccountFactory.
     *
     * @return \Illuminate\Support\Collection<int, Account>
     */
    public static function bulkLiquidityAccounts(int $count, string $moduleType = AccountModuleContract::OFFICE_MODULE_TYPE): \Illuminate\Support\Collection
    {
        if (!in_array($moduleType, [
            AccountModuleContract::OFFICE_MODULE_TYPE,
            AccountModuleContract::TOURISM_MODULE_TYPE,
        ], true)) {
            throw new \InvalidArgumentException(
                "Liquidity accounts require module_type to be '"
                .AccountModuleContract::OFFICE_MODULE_TYPE."' or '"
                .AccountModuleContract::TOURISM_MODULE_TYPE."', got '{$moduleType}'."
            );
        }

        $faker = app(\Faker\Generator::class);
        $created = collect();

        for ($i = 0; $i < $count; $i++) {
            $acct = Account::factory()->create([
                'name'        => 'STRESS-LIQ-'.str_pad((string)($i + 1), 4, '0', STR_PAD_LEFT).'-'.$faker->bothify('##??'),
                'module_type' => $moduleType,
                'module'      => null,
                'currency'    => 'EGP',
                'is_active'   => true,
                'balance'     => 0,
            ]);
            $created->push($acct);
        }
        return $created;
    }

    /**
     * Open an account with a starting balance by writing a balanced pair of
     * AccountEntry rows against the canonical STRESS-OPENING-TX transaction.
     *
     * Canonical double-entry opening pattern — mirrors
     * {@see \Database\Seeders\AccountingTestDataSeeder::seedAccountsAndOpeningEntries()}.
     *
     * Project convention: Account.balance = SUM(credit) - SUM(debit).
     *
     * For an asset account (cashbox / bank / wallet):
     *   - Credit $amount on the target → asset goes UP (balance increases)
     *   - Debit  $amount on capital     → equity claim goes UP (balance decreases — negative)
     *
     * For a liability-style opening (asDebit = true):
     *   - Debit  $amount on the target → liability goes UP (balance decreases, becomes more negative)
     *   - Credit $amount on capital    → capital goes UP
     *
     * Every openBalance() call adds 2 AccountEntry rows to the single shared
     * STRESS-OPENING-TX transaction. The reconciliation invariant
     * `SUM(debit) == SUM(credit)` per transaction holds at all times.
     *
     * NOTE: We use raw DB::table()->insert() inside LedgerBalanceMutationGuard
     * (the same pattern AccountingTestDataSeeder uses) so the
     * AccountService::debit() insufficient-balance check does not reject
     * the legitimate negative opening of the capital account.
     *
     * @param  float  $amount  positive amount
     * @param  bool   $asDebit  true = liability-style negative opening
     */
    public static function openBalance(Account $account, float $amount, ?int $actorId = null, string $notes = 'OPENING-STRESS', bool $asDebit = false): void
    {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Opening balance amount must be positive.');
        }

        LedgerBalanceMutationGuard::run(function () use ($account, $amount, $actorId, $notes, $asDebit) {
            $capital = self::openingCapitalAccount($actorId);
            $openingTx = self::openingTransaction($actorId);

            $previousBalance = (float) $account->balance;

            if ($asDebit) {
                $newTargetBalance = $previousBalance - $amount;
                $newCapitalBalance = (float) $capital->balance + $amount;

                DB::table('account_entries')->insert([
                    'account_id'     => $account->id,
                    'transaction_id' => $openingTx->id,
                    'debit'          => $amount,
                    'credit'         => 0,
                    'balance_after'  => $newTargetBalance,
                    'notes'          => $notes.' :: DEBIT '.$account->name,
                    'created_at'     => now(),
                    'updated_at'     => now(),
                ]);
                DB::table('account_entries')->insert([
                    'account_id'     => $capital->id,
                    'transaction_id' => $openingTx->id,
                    'debit'          => 0,
                    'credit'         => $amount,
                    'balance_after'  => $newCapitalBalance,
                    'notes'          => $notes.' :: CREDIT '.$capital->name,
                    'created_at'     => now(),
                    'updated_at'     => now(),
                ]);
            } else {
                $newTargetBalance = $previousBalance + $amount;
                $newCapitalBalance = (float) $capital->balance - $amount;

                DB::table('account_entries')->insert([
                    'account_id'     => $account->id,
                    'transaction_id' => $openingTx->id,
                    'debit'          => 0,
                    'credit'         => $amount,
                    'balance_after'  => $newTargetBalance,
                    'notes'          => $notes.' :: CREDIT '.$account->name,
                    'created_at'     => now(),
                    'updated_at'     => now(),
                ]);
                DB::table('account_entries')->insert([
                    'account_id'     => $capital->id,
                    'transaction_id' => $openingTx->id,
                    'debit'          => $amount,
                    'credit'         => 0,
                    'balance_after'  => $newCapitalBalance,
                    'notes'          => $notes.' :: DEBIT '.$capital->name,
                    'created_at'     => now(),
                    'updated_at'     => now(),
                ]);
            }

            // Update balances directly (inside the guard — boot guard allows).
            DB::table('accounts')->where('id', $account->id)->update(['balance' => $newTargetBalance]);
            DB::table('accounts')->where('id', $capital->id)->update(['balance' => $newCapitalBalance]);
        });
    }

    /**
     * Resolve (or lazily create) the dedicated stress Opening-Capital account.
     * This is the offsetting side of every opening balance. Its balance
     * legitimately becomes negative (in this project's reversed-sign
     * convention, where debit decreases balance, the equity claim grows
     * by being debited).
     *
     * NOTE: We do NOT cache the resolved Account in a static field. Each
     * call queries the DB. This is intentional — RefreshDatabase wraps
     * each PHPUnit test in a transaction that gets rolled back, so any
     * cached Account would have a stale id pointing to a row that no
     * longer exists. Re-querying is cheap.
     */
    public static function openingCapitalAccount(?int $actorId = null): Account
    {
        $existing = Account::query()->where('name', 'STRESS-OPENING-CAPITAL')->first();
        if ($existing) {
            return $existing;
        }

        // Account::create() does NOT fire the updating boot guard (only fires on update()),
        // so balance=0 at create is fine.
        return Account::create([
            'name'        => 'STRESS-OPENING-CAPITAL',
            'type'        => AccountType::Owner,
            'balance'     => 0,
            'currency'    => 'EGP',
            'is_active'   => true,
            'owner_type'  => Account::OWNER_TYPE_OWNER,
            'module_type' => 'general',
            'module'      => null,
            'is_module_vault' => false,
            'notes'       => 'Stress opening-balance capital — debit-side offset (legitimately negative)',
            'created_by'  => $actorId,
        ]);
    }

    /**
     * Resolve (or lazily create) the single shared STRESS-OPENING-TX
     * transaction. All opening-balance entries are written against this
     * one transaction so reconciliation sees a single, fully-balanced
     * double-entry pair per opening.
     *
     * NOTE: Not cached (see openingCapitalAccount() for the same rationale).
     */
    public static function openingTransaction(?int $actorId = null): Transaction
    {
        $existing = Transaction::query()->where('notes', 'STRESS-OPENING-TX')->first();
        if ($existing) {
            return $existing;
        }

        return Transaction::create([
            'type'           => 'transfer',
            'amount'         => 0,
            'currency'       => 'EGP',
            'module'         => 'general',
            'from_account_id'=> null,
            'to_account_id'  => null,
            'notes'          => 'STRESS-OPENING-TX',
            'created_by'     => $actorId,
        ]);
    }

    /**
     * Build a balanced random Transaction + 2 AccountEntry rows for stress
     * purposes. Used inside tests/scripts/stress_*.php hot-spot scenarios.
     *
     * @param  array<string,mixed>  $overrides
     */
    public static function directBalancedTransaction(array $overrides = []): Transaction
    {
        $faker = app(\Faker\Generator::class);
        $fromId = $overrides['from_account_id'] ?? Account::query()->inRandomOrder()->value('id');
        $toId   = $overrides['to_account_id']   ?? Account::query()->where('id','!=',$fromId)->inRandomOrder()->value('id');
        $amount = $overrides['amount'] ?? $faker->randomFloat(2, 100, 5000);

        if (!isset($overrides['created_by']) || empty($overrides['created_by'])) {
            $overrides['created_by'] = (int) (\App\Models\User::query()->min('id') ?? 1);
        }

        return LedgerBalanceMutationGuard::run(function () use ($fromId, $toId, $amount, $overrides) {
            return DB::transaction(function () use ($fromId, $toId, $amount, $overrides) {
                /** @var \App\Models\Transaction $tx */
                $tx = Transaction::create(array_merge([
                    'type'           => $overrides['type'] ?? 'transfer',
                    'amount'         => $amount,
                    'currency'       => $overrides['currency'] ?? 'EGP',
                    'module'         => $overrides['module'] ?? 'general',
                    'from_account_id'=> $fromId,
                    'to_account_id'  => $toId,
                    'notes'          => '[STRESS] '.Str::random(12),
                    'created_by'     => $overrides['created_by'],
                ], $overrides));

                $svc = app(\App\Services\Finance\AccountService::class);
                $svc->debit(Account::find($fromId), $amount, $tx->id);
                $svc->credit(Account::find($toId), $amount, $tx->id);
                return $tx->fresh();
            });
        });
    }

    /**
     * Yield chunk descriptors: ['offset' => N, 'size' => N].
     *
     * @return \Generator<int, array{offset:int,size:int}>
     */
    public static function chunks(int $total, int $chunkSize = self::CHUNK_SIZE): \Generator
    {
        $remaining = $total;
        $offset = 0;
        while ($remaining > 0) {
            $size = min($chunkSize, $remaining);
            yield ['offset' => $offset, 'size' => $size];
            $offset += $size;
            $remaining -= $size;
        }
    }
}
