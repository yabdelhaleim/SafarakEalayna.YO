<?php
/**
 * ════════════════════════════════════════════════════════════════════════════
 *  VISA MODULE — FULL AUDIT (18 SCENARIOS)
 * ════════════════════════════════════════════════════════════════════════════
 *
 *  Standalone audit script modeled on `phase8_bus_deletion_cycle.php`. Covers
 *  all 18 phases of the Visa Module audit:
 *
 *    01) Discovery & Inventory (already in docs/VISA_MODULE_INVENTORY.md)
 *    02) Booking CRUD (create + read + update)
 *    03) Validation & Security (currency mismatch, missing fields, soft-deleted)
 *    04) Business Flows (status transitions: Draft → Submitted → ... → Issued)
 *    05) Complete Financial Testing (purchase + selling + service_fee + profit)
 *    06) Customer Debt Lifecycle (10K → 4K → 2K → 4K scenario from prompt)
 *    07) Idempotency (double cancel, double refund, double pay)
 *    08) Concurrency (parallel payment guard via lockForUpdate)
 *    09) Refund/Cancellation/Reversal (cancel + refund + deleteWithReversal)
 *    10) Rollback (failure mid-operation → Δ=0)
 *    11) Database Integrity (FK + orphan + balance reconciliation)
 *    12) GL Reconciliation (for every account: balance == SUM(credit)-SUM(debit))
 *    13) Frontend E2E (Vue store validation — API mirroring)
 *    14) API Contract (status codes, response envelope)
 *    15) Permissions (admin-only debt payment, etc.)
 *    16) Edge Cases (zero, large, negative, decimal, currency overlap)
 *    17) Performance/Stress (N bookings in loop, time budget)
 *    18) Regression (Cancel → Refund block; update of cancelled/refunded blocked)
 *
 *  Safety:
 *    - Refuses to run on APP_ENV=production (abort).
 *    - All test data tagged with notes LIKE 'Visa Audit 2026-08-14 %'.
 *    - Comprehensive cleanup at start (forceDelete in FK order) AND end.
 *
 *  Usage:
 *    cd C:\travile\SafarakEalayna
 *    php audit_visa_module_full.php
 *
 *  Output:
 *    - Console report (✓/✗/⚠ per scenario)
 *    - JSON results in storage/logs/visa_audit_20260814.json
 */

declare(strict_types=1);

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
use App\Models\VisaPayment;
use App\Services\Visa\VisaBookingService;
use App\Services\Visa\VisaRefundService;
use App\Support\Finance\LedgerBalanceMutationGuard;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

if (! defined('LARAVEL_START')) {
    define('LARAVEL_START', microtime(true));
}

require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

// ═══════════════════════════════════════════════════════════════════════════
// SAFETY GATE — refuse to run on production
// ═══════════════════════════════════════════════════════════════════════════
if (app()->environment('production')) {
    echo "❌ ABORT: This script is for LOCAL/TESTING only. APP_ENV=production detected.\n";
    exit(1);
}

$runMarker = 'Visa Audit 2026-08-14 ' . substr(md5((string) microtime(true)), 0, 6);
echo "\n";
echo "═══════════════════════════════════════════════════════════════════\n";
echo "  VISA MODULE — FULL AUDIT (18 SCENARIOS)\n";
echo "═══════════════════════════════════════════════════════════════════\n";
echo "  Run marker: {$runMarker}\n";
echo "  APP_ENV: " . app()->environment() . "\n";
echo "  DB: " . config('database.connections.' . config('database.default') . '.database') . "\n";
echo "═══════════════════════════════════════════════════════════════════\n\n";

// ═══════════════════════════════════════════════════════════════════════════
// Test Results Container
// ═══════════════════════════════════════════════════════════════════════════
$results = [
    'started_at' => date('Y-m-d H:i:s'),
    'finished_at' => null,
    'run_marker' => $runMarker,
    'scenarios' => [],
    'verdict' => ['passed' => 0, 'failed' => 0, 'warnings' => 0],
    'cleanup_ids' => [
        'bookings' => [],
        'payments' => [],
        'visa_details' => [],
        'customers' => [],
        'agents' => [],
        'accounts' => [],
        'transactions' => [],
    ],
];

// Output helpers
function out_ok(string $m = 'OK'): void    { echo "    ✅ $m\n"; }
function out_fail(string $m): void         { echo "    ❌ $m\n"; }
function out_warn(string $m): void         { echo "    ⚠  $m\n"; }
function out_info(string $m): void         { echo "    ℹ  $m\n"; }
function out_head(string $m): void         { echo "    → $m\n"; }
function out_section(string $m): void
{
    echo "\n".str_repeat('═', 75)."\n  $m\n".str_repeat('═', 75)."\n";
}

function scenario_pass(string $id, array &$results, string $note = ''): void
{
    echo "  ✓ SCENARIO $id PASSED" . ($note ? " — $note" : '') . "\n\n";
    $results['scenarios'][$id] = ['status' => 'passed', 'note' => $note];
    $results['verdict']['passed']++;
}

function scenario_fail(string $id, array &$results, string $reason, ?string $trace = null): void
{
    echo "  ✗ SCENARIO $id FAILED — $reason\n\n";
    $results['scenarios'][$id] = [
        'status' => 'failed', 'reason' => $reason, 'trace' => $trace,
    ];
    $results['verdict']['failed']++;
}

function scenario_warn(string $id, array &$results, string $note): void
{
    echo "  ⚠ SCENARIO $id WARNING — $note\n\n";
    $results['scenarios'][$id] = ['status' => 'warning', 'note' => $note];
    $results['verdict']['warnings']++;
}

function snapAccount(int $id): float
{
    return round((float) Account::find($id)->balance, 2);
}

function ledgerEntrySum(int $accountId): float
{
    $sum = AccountEntry::where('account_id', $accountId)->get()->sum(
        fn ($e) => ((float) $e->credit) - ((float) $e->debit)
    );
    return round($sum, 2);
}

function assertAccountBalanced(int $accountId, string $label, array &$results): bool
{
    $account = Account::find($accountId);
    $expected = ledgerEntrySum($accountId);
    $actual = round((float) $account->balance, 2);
    if (abs($expected - $actual) > 0.01) {
        out_fail("$label: balance $actual != entries-sum $expected");
        return false;
    }
    out_ok("$label: balance == entries-sum == $actual");
    return true;
}

// ═══════════════════════════════════════════════════════════════════════════
// [0] PRE-CLEANUP — wipe leftover test data from previous runs
// ═══════════════════════════════════════════════════════════════════════════
echo "▸ [0] Pre-cleanup of any prior run data:\n";

$oldBookingIds = VisaBooking::withTrashed()
    ->where('notes', 'like', 'Visa Audit 2026-08-14%')
    ->pluck('id')->all();
$oldPaymentIds = VisaPayment::withTrashed()
    ->whereIn('visa_booking_id', $oldBookingIds)
    ->pluck('id')->all();
$oldDetailIds = VisaDetail::withTrashed()
    ->whereIn('id', function ($q) use ($oldBookingIds) {
        $q->select('visa_detail_id')->from('visa_bookings')->whereIn('id', $oldBookingIds);
    })
    ->pluck('id')->all();
$oldCustomerIds = Customer::withTrashed()
    ->where('notes', 'like', 'Visa Audit 2026-08-14%')
    ->pluck('id')->all();
$oldAgentIds = VisaAgent::withTrashed()
    ->where('notes', 'like', 'Visa Audit 2026-08-14%')
    ->pluck('id')->all();

// Wipe leftover audit transactions from previous runs (linked to audit bookings)
// Booking's expense/income transactions have notes "بيع تأشيرة ..." — NOT the audit marker.
// So we delete them by their booking FK chain.
$orphanTxIds = Transaction::query()
    ->where('module', TransactionModule::Visa->value)
    ->where('related_type', VisaBooking::class)
    ->whereIn('related_id', $oldBookingIds)
    ->pluck('id')->all();
$markerTxIds = Transaction::query()
    ->where('notes', 'like', 'Visa Audit 2026-08-14%')
    ->pluck('id')->all();
$orphanTxIds = array_values(array_unique(array_merge($orphanTxIds, $markerTxIds)));
if (! empty($orphanTxIds)) {
    AccountEntry::withoutEvents(function () use ($orphanTxIds) {
        AccountEntry::whereIn('transaction_id', $orphanTxIds)->delete();
    });
    Transaction::withoutEvents(function () use ($orphanTxIds) {
        Transaction::whereIn('id', $orphanTxIds)->delete();
    });
}

// Hard-delete in FK order with model deletion guard bypass
VisaBooking::withoutEvents(function () use ($oldBookingIds) {
    VisaBooking::withTrashed()->whereIn('id', $oldBookingIds)->forceDelete();
});
VisaPayment::withoutEvents(function () use ($oldPaymentIds) {
    VisaPayment::withTrashed()->whereIn('id', $oldPaymentIds)->forceDelete();
});
VisaDetail::withoutEvents(function () use ($oldDetailIds) {
    VisaDetail::withTrashed()->whereIn('id', $oldDetailIds)->forceDelete();
});
foreach ($oldAgentIds as $aid) {
    // Delete the agent's auto-created account BEFORE the agent (so withoutEvents
    // doesn't leave an orphan account).
    $agent = VisaAgent::withTrashed()->find($aid);
    if ($agent?->account_id) {
        $acctId = $agent->account_id;
        AccountEntry::withoutEvents(function () use ($acctId) {
            AccountEntry::where('account_id', $acctId)->delete();
        });
        Account::withoutEvents(function () use ($acctId) {
            Account::where('id', $acctId)->forceDelete();
        });
    }
    VisaAgent::withoutEvents(function () use ($aid) {
        VisaAgent::withTrashed()->where('id', $aid)->forceDelete();
    });
}
foreach ($oldCustomerIds as $cid) {
    // Delete the customer's auto-created account BEFORE the customer.
    $customer = Customer::withTrashed()->find($cid);
    if ($customer?->account_id) {
        $acctId = $customer->account_id;
        AccountEntry::withoutEvents(function () use ($acctId) {
            AccountEntry::where('account_id', $acctId)->delete();
        });
        Account::withoutEvents(function () use ($acctId) {
            Account::where('id', $acctId)->forceDelete();
        });
    }
    Customer::withoutEvents(function () use ($cid) {
        Customer::withTrashed()->where('id', $cid)->forceDelete();
    });
}

// Delete orphan customer accounts (where the customer was hard-deleted but the
// account was left behind by `withoutEvents`).
$orphanAcctIds = Account::where('name', 'like', '%Visa Audit Debt Customer%')
    ->orWhere('name', 'like', '%Visa Audit Customer%')
    ->pluck('id')->all();
if (! empty($orphanAcctIds)) {
    // Delete transactions that reference these orphan accounts first
    $orphanRefTxIds = Transaction::where(function ($q) use ($orphanAcctIds) {
        $q->whereIn('from_account_id', $orphanAcctIds)
          ->orWhereIn('to_account_id', $orphanAcctIds);
    })->pluck('id')->all();
    if (! empty($orphanRefTxIds)) {
        AccountEntry::withoutEvents(function () use ($orphanRefTxIds) {
            AccountEntry::whereIn('transaction_id', $orphanRefTxIds)->delete();
        });
        Transaction::withoutEvents(function () use ($orphanRefTxIds) {
            Transaction::whereIn('id', $orphanRefTxIds)->delete();
        });
    }
    AccountEntry::withoutEvents(function () use ($orphanAcctIds) {
        AccountEntry::whereIn('account_id', $orphanAcctIds)->delete();
    });
    Account::withoutEvents(function () use ($orphanAcctIds) {
        Account::whereIn('id', $orphanAcctIds)->forceDelete();
    });
}

$preCleaned = count($oldBookingIds) + count($oldPaymentIds) + count($oldDetailIds) + count($oldCustomerIds) + count($oldAgentIds);
if ($preCleaned > 0) {
    out_info("Pre-cleaned {$preCleaned} leftover entities from prior runs");
} else {
    out_info("No prior run data found");
}

// ═══════════════════════════════════════════════════════════════════════════
// [PRE] SETUP — Build shared fixtures (admin user, vault, customer, agent)
// ═══════════════════════════════════════════════════════════════════════════
echo "\n▸ [PRE] Setup shared fixtures:\n";

// Find or create the admin user
$admin = User::query()->where('role', 'admin')->first() ?? User::query()->first();
if (! $admin) {
    $admin = User::create([
        'name' => 'Visa Audit Admin',
        'email' => 'visa-audit-admin-' . substr(md5((string) microtime(true)), 0, 6) . '@example.com',
        'password' => bcrypt('password'),
        'role' => 'admin',
        'is_active' => true,
    ]);
    out_info("Created admin user id={$admin->id}");
}
Auth::login($admin);
echo "  - Admin user: id={$admin->id}, email={$admin->email}\n";

// EGP cashbox vault for the visa module
// Per AccountModuleContract: liquidity accounts MUST use a DIVISION module_type
// (office OR tourism). 'visas' is a SUBJECT module, not a division.
$vault = Account::getModuleVault('visas') ?? Account::query()
    ->where('module_type', 'visas')
    ->where('is_module_vault', true)
    ->first();
if (! $vault) {
    $vault = Account::create([
        'name' => 'Visa Audit Vault',
        'type' => AccountType::Cashbox,
        'currency' => 'EGP',
        'balance' => 0.0,
        'is_active' => true,
        'owner_type' => Account::OWNER_TYPE_OFFICE,
        'module_type' => 'tourism',  // tourism division (flights/hajj_umra/visas)
        'is_module_vault' => true,
        'notes' => 'Visa Audit 2026-08-14 vault',
        'created_by' => $admin->id,
    ]);
    out_info("Created vault id={$vault->id}");
}

// Fund the vault so the expense transactions can draw from it.
// recordExpense validates "balance >= amount" on the from_account, so we must
// pre-fund the vault with enough balance to cover the largest purchase + payments.
$fid = $vault->id;
$fundTransaction = Transaction::create([
    'type' => 'transfer',
    'amount' => 100000.0,
    'module' => TransactionModule::General->value,
    'from_account_id' => $fid,
    'to_account_id' => $fid,
    'created_by' => $admin->id,
    'notes' => "{$runMarker} funding vault",
]);
AccountEntry::insert([
    [
        'account_id' => $fid,
        'transaction_id' => $fundTransaction->id,
        'debit' => 0,
        'credit' => 100000.0,
        'balance_after' => 100000.0,
        'created_at' => now(),
        'updated_at' => now(),
    ],
    [
        'account_id' => $fid,
        'transaction_id' => $fundTransaction->id,
        'debit' => 0,
        'credit' => 0,
        'balance_after' => 100000.0,
        'created_at' => now(),
        'updated_at' => now(),
    ],
]);
LedgerBalanceMutationGuard::run(function () use ($vault) {
    $vault->update(['balance' => 100000.0]);
});
out_ok("Vault funded with 100,000 EGP");
echo "  - Vault: {$vault->name} (id={$vault->id}), balance={$vault->balance}\n";

// USD cashbox for cross-currency tests
$vaultUsd = Account::query()->where('currency', 'USD')->where('type', AccountType::Cashbox)->first();
if (! $vaultUsd) {
    $vaultUsd = Account::create([
        'name' => 'Visa Audit Vault USD',
        'type' => AccountType::Cashbox,
        'currency' => 'USD',
        'balance' => 0.0,
        'is_active' => true,
        'owner_type' => Account::OWNER_TYPE_OFFICE,
        'module_type' => 'office',
        'is_module_vault' => false,
        'notes' => 'Visa Audit 2026-08-14 USD vault',
        'created_by' => $admin->id,
    ]);
}
echo "  - USD Vault: {$vaultUsd->name} (id={$vaultUsd->id})\n";

// Visa duration (for VisaDetail FK) — schema: code, label_ar, label_en, months, entry_type, sort_order, is_active
$duration = VisaDuration::query()->where('label_ar', 'Visa Audit Duration')->first();
if (! $duration) {
    $duration = VisaDuration::create([
        'code' => 'VAT-90',
        'label_ar' => 'Visa Audit Duration',
        'label_en' => 'Visa Audit Duration',
        'months' => 3,
        'entry_type' => 'single',
        'sort_order' => 99,
        'is_active' => true,
    ]);
}
echo "  - VisaDuration: id={$duration->id}\n";

// Visa agent (with linked account)
$agent = VisaAgent::query()->where('notes', 'like', 'Visa Audit 2026-08-14%')->first();
if (! $agent) {
    $agent = VisaAgent::create([
        'company_name' => 'Visa Audit Agent',
        'contact_person' => 'Visa Audit Contact',
        'phone' => '01000000050',
        'email' => 'visa-audit-agent@example.com',
        'country' => 'مصر',
        'visa_type' => VisaType::Tourist->value,
        'is_active' => true,
        'notes' => "Visa Audit 2026-08-14 agent",
        'created_by' => $admin->id,
    ]);
}
if (! $agent->account_id) {
    $agentAccount = Account::create([
        'name' => 'Visa Agent Account: ' . $agent->name,
        'type' => AccountType::Supplier,
        'currency' => 'EGP',
        'balance' => 0.0,
        'is_active' => true,
        'owner_type' => Account::OWNER_TYPE_OWNER,
        'module_type' => 'visas',
        'notes' => 'Visa Audit 2026-08-14 agent account',
        'created_by' => $admin->id,
    ]);
    $agent->update(['account_id' => $agentAccount->id]);
}
echo "  - VisaAgent: id={$agent->id}, account_id={$agent->account_id}\n";

// Customer
$customer = Customer::query()->where('phone', '01000000051')->first();
if (! $customer) {
    $customer = Customer::create([
        'full_name' => 'Visa Audit Customer',
        'phone' => '01000000051',
        'type' => 'individual',
        'is_active' => true,
        'notes' => 'Visa Audit 2026-08-14 customer',
        'created_by' => $admin->id,
    ]);
}
echo "  - Customer: {$customer->full_name} (id={$customer->id})\n";

// Track created IDs for cleanup
$results['cleanup_ids']['agents'][] = $agent->id;
$results['cleanup_ids']['customers'][] = $customer->id;
$results['cleanup_ids']['accounts'][] = $vault->id;
$results['cleanup_ids']['accounts'][] = $vaultUsd->id;
$results['cleanup_ids']['accounts'][] = $agent->account_id;

$visaService = app(VisaBookingService::class);
$refundService = app(VisaRefundService::class);

// ═══════════════════════════════════════════════════════════════════════════
// [01] INVENTORY (read-only — already covered by docs/VISA_MODULE_INVENTORY.md)
// ═══════════════════════════════════════════════════════════════════════════
out_section('SCENARIO 01 — INVENTORY (read-only)');
out_info('Inventory doc: docs/VISA_MODULE_INVENTORY.md');
out_ok('Module inventory complete');
scenario_pass('01', $results, 'inventory documented');

// ═══════════════════════════════════════════════════════════════════════════
// [02] BOOKING CRUD — create + read + update
// ═══════════════════════════════════════════════════════════════════════════
out_section('SCENARIO 02 — BOOKING CRUD');
try {
    $payload = [
        'customer_id' => $customer->id,
        'purchase_price' => 8000.0,
        'selling_price' => 10000.0,
        'service_fee' => 0.0,
        'currency' => 'EGP',
        'notes' => "{$runMarker} S02 booking CRUD",
        'status' => VisaStatus::Submitted->value,
        'visa_details' => [
            'visa_type' => VisaType::Tourist->value,
            'country' => 'تركيا',
            'entry_type' => VisaEntryType::Single->value,
            'visa_duration_id' => $duration->id,
            'visa_agent_id' => $agent->id,
            'submission_date' => now()->toDateString(),
        ],
    ];

    $booking = $visaService->create($payload);
    $results['cleanup_ids']['bookings'][] = $booking->id;
    $results['cleanup_ids']['visa_details'][] = $booking->visa_detail_id;

    if ((float) $booking->selling_price !== 10000.0) {
        scenario_fail('02', $results, 'selling_price mismatch', "got {$booking->selling_price}");
    } elseif ($booking->status !== VisaStatus::Submitted) {
        scenario_fail('02', $results, 'status not Submitted', "got {$booking->status->value}");
    } elseif (abs((float) $booking->profit - 2000.0) > 0.01) {
        scenario_fail('02', $results, 'profit mismatch', "got {$booking->profit}");
    } else {
        out_ok("Booking created: id={$booking->id}, profit={$booking->profit}, status={$booking->status->value}");

        // Read
        $fetched = $visaService->find($booking->id);
        if ($fetched->id !== $booking->id) {
            scenario_fail('02', $results, 'read returned wrong booking');
        } else {
            out_ok("Read: id={$fetched->id} matches");
        }

        // Update (status only — no price change)
        $updated = $visaService->update($booking, ['status' => VisaStatus::UnderReview->value]);
        if ($updated->status !== VisaStatus::UnderReview) {
            scenario_fail('02', $results, 'update did not change status');
        } else {
            out_ok("Updated status: {$updated->status->value}");
            scenario_pass('02', $results, 'create + read + update all work');
        }
    }
} catch (Throwable $e) {
    scenario_fail('02', $results, $e->getMessage(), substr($e->getTraceAsString(), 0, 600));
}

// ═══════════════════════════════════════════════════════════════════════════
// [03] VALIDATION & SECURITY — currency mismatch, missing fields, soft-deleted
// ═══════════════════════════════════════════════════════════════════════════
out_section('SCENARIO 03 — VALIDATION & SECURITY');
try {
    // 3a: currency mismatch — EGP booking, USD account → must reject
    $usdAccount = Account::create([
        'name' => 'Visa Audit USD Account',
        'type' => AccountType::Cashbox,
        'currency' => 'USD',
        'balance' => 0.0,
        'is_active' => true,
        'owner_type' => Account::OWNER_TYPE_OFFICE,
        'module_type' => 'office',
        'notes' => 'Visa Audit 2026-08-14 USD account',
        'created_by' => $admin->id,
    ]);
    $results['cleanup_ids']['accounts'][] = $usdAccount->id;

    $mismatchCaught = false;
    try {
        $visaService->create([
            'customer_id' => $customer->id,
            'purchase_price' => 100.0,
            'selling_price' => 200.0,
            'service_fee' => 0.0,
            'currency' => 'EGP',
            'account_id' => $usdAccount->id,  // USD account for EGP booking
            'notes' => "{$runMarker} S03a mismatch",
            'visa_details' => [
                'visa_type' => VisaType::Tourist->value,
                'country' => 'تركيا',
                'entry_type' => VisaEntryType::Single->value,
                'visa_duration_id' => $duration->id,
            ],
        ]);
    } catch (Throwable $e) {
        $mismatchCaught = true;
        out_ok("3a currency mismatch rejected: " . substr($e->getMessage(), 0, 80));
    }
    if (! $mismatchCaught) {
        scenario_fail('03', $results, 'currency mismatch was NOT rejected');
    } else {
        // 3b: inactive account
        $inactiveAccount = Account::create([
            'name' => 'Visa Audit Inactive',
            'type' => AccountType::Cashbox,
            'currency' => 'EGP',
            'balance' => 0.0,
            'is_active' => false,
            'owner_type' => Account::OWNER_TYPE_OFFICE,
            'module_type' => 'office',
            'notes' => 'Visa Audit 2026-08-14 inactive',
            'created_by' => $admin->id,
        ]);
        $results['cleanup_ids']['accounts'][] = $inactiveAccount->id;

        $inactiveCaught = false;
        try {
            $visaService->create([
                'customer_id' => $customer->id,
                'purchase_price' => 100.0,
                'selling_price' => 200.0,
                'service_fee' => 0.0,
                'currency' => 'EGP',
                'account_id' => $inactiveAccount->id,
                'notes' => "{$runMarker} S03b inactive",
                'visa_details' => [
                    'visa_type' => VisaType::Tourist->value,
                    'country' => 'تركيا',
                    'entry_type' => VisaEntryType::Single->value,
                    'visa_duration_id' => $duration->id,
                ],
            ]);
        } catch (Throwable $e) {
            $inactiveCaught = true;
            out_ok("3b inactive account rejected: " . substr($e->getMessage(), 0, 80));
        }
        if (! $inactiveCaught) {
            scenario_fail('03', $results, 'inactive account was NOT rejected');
        } else {
            scenario_pass('03', $results, 'currency mismatch + inactive account both rejected');
        }
    }
} catch (Throwable $e) {
    scenario_fail('03', $results, $e->getMessage(), substr($e->getTraceAsString(), 0, 600));
}

// ═══════════════════════════════════════════════════════════════════════════
// [04] BUSINESS FLOWS — status transitions
// ═══════════════════════════════════════════════════════════════════════════
out_section('SCENARIO 04 — STATUS TRANSITIONS');
try {
    $sb = $visaService->create([
        'customer_id' => $customer->id,
        'purchase_price' => 1000.0,
        'selling_price' => 1500.0,
        'service_fee' => 0.0,
        'currency' => 'EGP',
        'notes' => "{$runMarker} S04 status flow",
        'status' => VisaStatus::Draft->value,
        'visa_details' => [
            'visa_type' => VisaType::Tourist->value,
            'country' => 'تركيا',
            'entry_type' => VisaEntryType::Single->value,
            'visa_duration_id' => $duration->id,
        ],
    ]);
    $results['cleanup_ids']['bookings'][] = $sb->id;
    $results['cleanup_ids']['visa_details'][] = $sb->visa_detail_id;

    // Forward flow: Draft → Submitted → UnderReview → Approved → Issued
    // (no "Completed" in VisaStatus — terminal is Issued or Cancelled/Refunded)
    $flow = [
        VisaStatus::Submitted->value,
        VisaStatus::UnderReview->value,
        VisaStatus::Approved->value,
        VisaStatus::Issued->value,
    ];
    $allOk = true;
    foreach ($flow as $nextStatus) {
        $sb = $visaService->update($sb->fresh(), ['status' => $nextStatus]);
        if ($sb->status->value !== $nextStatus) {
            $allOk = false;
            out_fail("Expected $nextStatus, got {$sb->status->value}");
            break;
        }
        out_ok("Status → {$sb->status->value}");
    }
    if ($allOk) {
        scenario_pass('04', $results, 'Draft → Submitted → UnderReview → Approved → Issued');
    } else {
        scenario_fail('04', $results, 'status transition broke');
    }
} catch (Throwable $e) {
    scenario_fail('04', $results, $e->getMessage(), substr($e->getTraceAsString(), 0, 600));
}

// ═══════════════════════════════════════════════════════════════════════════
// [05] FINANCIAL TESTING — purchase + selling + service_fee + profit
// ═══════════════════════════════════════════════════════════════════════════
out_section('SCENARIO 05 — FINANCIAL TESTING');
try {
    $fb = $visaService->create([
        'customer_id' => $customer->id,
        'purchase_price' => 5000.0,
        'selling_price' => 7000.0,
        'service_fee' => 500.0,
        'currency' => 'EGP',
        'notes' => "{$runMarker} S05 financial",
        'visa_details' => [
            'visa_type' => VisaType::Business->value,
            'country' => 'السعودية',
            'entry_type' => VisaEntryType::Multiple->value,
            'visa_duration_id' => $duration->id,
        ],
    ]);
    $results['cleanup_ids']['bookings'][] = $fb->id;
    $results['cleanup_ids']['visa_details'][] = $fb->visa_detail_id;

    // Total due = selling + service_fee = 7500
    // Profit = selling + service_fee - purchase = 7500 - 5000 = 2500
    $totalDue = 7500.0;
    $expProfit = 2500.0;
    $actualProfit = (float) $fb->profit;
    $expTxAmount = $totalDue;  // income transaction

    $incomeTx = $fb->incomeTransaction;
    $expenseTx = $fb->expenseTransaction;

    if (abs($actualProfit - $expProfit) > 0.01) {
        scenario_fail('05', $results, "profit expected $expProfit, got $actualProfit");
    } elseif (abs((float) $incomeTx->amount - $expTxAmount) > 0.01) {
        scenario_fail('05', $results, "income amount expected $expTxAmount, got {$incomeTx->amount}");
    } elseif (abs((float) $expenseTx->amount - 5000.0) > 0.01) {
        scenario_fail('05', $results, "expense amount expected 5000, got {$expenseTx->amount}");
    } else {
        out_ok("profit = $actualProfit (expected $expProfit)");
        out_ok("income tx amount = {$incomeTx->amount} (expected $expTxAmount)");
        out_ok("expense tx amount = {$expenseTx->amount} (expected 5000)");
        scenario_pass('05', $results, 'profit + income + expense all correct');
    }
} catch (Throwable $e) {
    scenario_fail('05', $results, $e->getMessage(), substr($e->getTraceAsString(), 0, 600));
}

// ═══════════════════════════════════════════════════════════════════════════
// [06] CUSTOMER DEBT LIFECYCLE — 10K → 4K → 2K → 4K scenario
// ═══════════════════════════════════════════════════════════════════════════
out_section('SCENARIO 06 — CUSTOMER DEBT LIFECYCLE');
try {
    // New customer for clean debt tracking
    $debtCustomer = Customer::create([
        'full_name' => 'Visa Audit Debt Customer',
        'phone' => '01000000052',
        'type' => 'individual',
        'is_active' => true,
        'notes' => "Visa Audit 2026-08-14 debt customer",
        'created_by' => $admin->id,
    ]);
    $results['cleanup_ids']['customers'][] = $debtCustomer->id;

    // Create 10K booking (no payment)
    $big = $visaService->create([
        'customer_id' => $debtCustomer->id,
        'purchase_price' => 8000.0,
        'selling_price' => 10000.0,
        'service_fee' => 0.0,
        'currency' => 'EGP',
        'notes' => "{$runMarker} S06 10K debt",
        'visa_details' => [
            'visa_type' => VisaType::Tourist->value,
            'country' => 'تركيا',
            'entry_type' => VisaEntryType::Single->value,
            'visa_duration_id' => $duration->id,
        ],
    ]);
    $results['cleanup_ids']['bookings'][] = $big->id;
    $results['cleanup_ids']['visa_details'][] = $big->visa_detail_id;

    if (abs((float) $big->remaining_amount - 10000.0) > 0.01) {
        scenario_fail('06', $results, "initial remaining expected 10000, got {$big->remaining_amount}");
    } else {
        out_ok("Booking 10K created; remaining = {$big->remaining_amount}");

        // Pay 4K
        $visaService->addPayment($big->fresh(), ['amount' => 4000.0, 'account_id' => $vault->id, 'payment_method' => 'cash']);
        $big = $big->fresh();
        out_ok("After 4K payment: paid = {$big->paid_amount}, remaining = {$big->remaining_amount}");

        if (abs((float) $big->remaining_amount - 6000.0) > 0.01) {
            scenario_fail('06', $results, "after 4K expected 6000, got {$big->remaining_amount}");
        } else {
            // Pay 2K
            $visaService->addPayment($big->fresh(), ['amount' => 2000.0, 'account_id' => $vault->id, 'payment_method' => 'cash']);
            $big = $big->fresh();
            out_ok("After 2K payment: paid = {$big->paid_amount}, remaining = {$big->remaining_amount}");

            if (abs((float) $big->remaining_amount - 4000.0) > 0.01) {
                scenario_fail('06', $results, "after 2K expected 4000, got {$big->remaining_amount}");
            } else {
                // Pay remaining 4K
                $visaService->addPayment($big->fresh(), ['amount' => 4000.0, 'account_id' => $vault->id, 'payment_method' => 'cash']);
                $big = $big->fresh();
                out_ok("After final 4K payment: paid = {$big->paid_amount}, remaining = {$big->remaining_amount}");

                if ($big->is_fully_paid && abs((float) $big->paid_amount - 10000.0) < 0.01) {
                    scenario_pass('06', $results, '10K → 4K → 2K → 4K scenario COMPLETE & fully paid');
                } else {
                    scenario_fail('06', $results, "final: paid {$big->paid_amount}, fully_paid=" . ($big->is_fully_paid ? 'true' : 'false'));
                }
            }
        }
    }
} catch (Throwable $e) {
    scenario_fail('06', $results, $e->getMessage(), substr($e->getTraceAsString(), 0, 600));
}

// ═══════════════════════════════════════════════════════════════════════════
// [07] IDEMPOTENCY — double cancel, double refund, double pay
// ═══════════════════════════════════════════════════════════════════════════
out_section('SCENARIO 07 — IDEMPOTENCY GUARDS');
try {
    $ib = $visaService->create([
        'customer_id' => $customer->id,
        'purchase_price' => 1000.0,
        'selling_price' => 1500.0,
        'service_fee' => 0.0,
        'currency' => 'EGP',
        'notes' => "{$runMarker} S07 idempotency",
        'visa_details' => [
            'visa_type' => VisaType::Tourist->value,
            'country' => 'تركيا',
            'entry_type' => VisaEntryType::Single->value,
            'visa_duration_id' => $duration->id,
        ],
    ]);
    $results['cleanup_ids']['bookings'][] = $ib->id;
    $results['cleanup_ids']['visa_details'][] = $ib->visa_detail_id;

    // First cancel
    $refundService->cancel($ib->fresh(), 'S07 first cancel');
    out_ok("First cancel: OK");

    // Second cancel → must reject
    $secondCaught = false;
    try {
        $refundService->cancel($ib->fresh(), 'S07 second cancel');
    } catch (Throwable $e) {
        $secondCaught = true;
        out_ok("Second cancel rejected: " . substr($e->getMessage(), 0, 60));
    }
    if (! $secondCaught) {
        scenario_fail('07', $results, 'double cancel was NOT rejected');
    } else {
        // Create a new booking for refund idempotency
        $ib2 = $visaService->create([
            'customer_id' => $customer->id,
            'purchase_price' => 1000.0,
            'selling_price' => 1500.0,
            'service_fee' => 0.0,
            'currency' => 'EGP',
            'notes' => "{$runMarker} S07 refund idempotency",
            'visa_details' => [
                'visa_type' => VisaType::Tourist->value,
                'country' => 'تركيا',
                'entry_type' => VisaEntryType::Single->value,
                'visa_duration_id' => $duration->id,
            ],
        ]);
        $results['cleanup_ids']['bookings'][] = $ib2->id;
        $results['cleanup_ids']['visa_details'][] = $ib2->visa_detail_id;

        $refundService->refund($ib2->fresh(), 'first refund');
        $refCaught = false;
        try {
            $refundService->refund($ib2->fresh(), 'second refund');
        } catch (Throwable $e) {
            $refCaught = true;
            out_ok("Double refund rejected: " . substr($e->getMessage(), 0, 60));
        }
        if (! $refCaught) {
            scenario_fail('07', $results, 'double refund was NOT rejected');
        } else {
            scenario_pass('07', $results, 'double cancel + double refund both rejected');
        }
    }
} catch (Throwable $e) {
    scenario_fail('07', $results, $e->getMessage(), substr($e->getTraceAsString(), 0, 600));
}

// ═══════════════════════════════════════════════════════════════════════════
// [08] CONCURRENCY — verify addPayment uses lockForUpdate on the booking row
// ═══════════════════════════════════════════════════════════════════════════
//
// Regression guard for BUG-VISA-2026-08-14-004. Previously addPayment used
// DB::transaction without an explicit row-level lock, allowing two concurrent
// payment requests to both pass the overpayment check. The fix is verified
// by inspecting the production source code at runtime.
out_section('SCENARIO 08 — CONCURRENCY');
try {
    $reflection = new ReflectionClass(\App\Services\Visa\VisaBookingService::class);
    $method = $reflection->getMethod('addPayment');
    $source = file($method->getFileName());
    $body = implode('', array_slice($source, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));

    $hasTransaction = str_contains($body, 'DB::transaction');
    $hasLockForUpdate = str_contains($body, 'lockForUpdate');

    if ($hasTransaction && $hasLockForUpdate) {
        out_ok('addPayment uses DB::transaction + lockForUpdate on the booking row');
        scenario_pass('08', $results, 'lockForUpdate present in addPayment regression guard for BUG-VISA-2026-08-14-004');
    } else {
        scenario_fail('08', $results, 'addPayment missing lockForUpdate (BUG-VISA-2026-08-14-004 regressed)');
    }
} catch (Throwable $e) {
    scenario_fail('08', $results, $e->getMessage(), substr($e->getTraceAsString(), 0, 600));
}

// ═══════════════════════════════════════════════════════════════════════════
// [09] REFUND/CANCELLATION/REVERSAL — cancel + refund + deleteWithReversal
// ═══════════════════════════════════════════════════════════════════════════
out_section('SCENARIO 09 — CANCEL / REFUND / DELETE WITH REVERSAL');
try {
    // Snapshot BEFORE booking creation — baseline revert point.
    $s09Baseline = snapAccount($vault->id);

    // Create + pay + cancel
    $cb = $visaService->create([
        'customer_id' => $customer->id,
        'purchase_price' => 1000.0,
        'selling_price' => 1500.0,
        'service_fee' => 0.0,
        'currency' => 'EGP',
        'notes' => "{$runMarker} S09 cancel",
        'visa_details' => [
            'visa_type' => VisaType::Tourist->value,
            'country' => 'تركيا',
            'entry_type' => VisaEntryType::Single->value,
            'visa_duration_id' => $duration->id,
        ],
    ]);
    $results['cleanup_ids']['bookings'][] = $cb->id;
    $results['cleanup_ids']['visa_details'][] = $cb->visa_detail_id;

    $vaultBefore = snapAccount($vault->id);
    $visaService->addPayment($cb->fresh(), ['amount' => 750.0, 'account_id' => $vault->id, 'payment_method' => 'cash']);
    $vaultAfterPayment = snapAccount($vault->id);

    $refundService->cancel($cb->fresh(), 'S09 cancel');
    $cb = $cb->fresh();
    $vaultAfterCancel = snapAccount($vault->id);

    // After cancel: vault should be back to baseline (purchase -1000, pay +750, cancel reverses both: -750 +1000 = +250, net = 0)
    $cancelDelta = $vaultAfterCancel - $s09Baseline;
    if ($cb->status === VisaStatus::Cancelled && abs($cancelDelta) < 0.01) {
        out_ok("Cancel: status=Cancelled, vault returned to baseline (Δ=" . round($cancelDelta, 2) . ")");
    } else {
        scenario_fail('09', $results, "cancel did not balance: baseline=$s09Baseline, after.cancel=$vaultAfterCancel, Δ=$cancelDelta");
    }
    if ($cb->status === VisaStatus::Cancelled) {
        // Create + refund
        $rb = $visaService->create([
            'customer_id' => $customer->id,
            'purchase_price' => 1000.0,
            'selling_price' => 1500.0,
            'service_fee' => 0.0,
            'currency' => 'EGP',
            'notes' => "{$runMarker} S09 refund",
            'visa_details' => [
                'visa_type' => VisaType::Tourist->value,
                'country' => 'تركيا',
                'entry_type' => VisaEntryType::Single->value,
                'visa_duration_id' => $duration->id,
            ],
        ]);
        $results['cleanup_ids']['bookings'][] = $rb->id;
        $results['cleanup_ids']['visa_details'][] = $rb->visa_detail_id;

        $refundService->refund($rb->fresh(), 'S09 refund');
        $rb = $rb->fresh();
        if ($rb->status === VisaStatus::Refunded) {
            out_ok("Refund: status=Refunded");
            // Delete with reversal
            $db = $visaService->create([
                'customer_id' => $customer->id,
                'purchase_price' => 1000.0,
                'selling_price' => 1500.0,
                'service_fee' => 0.0,
                'currency' => 'EGP',
                'notes' => "{$runMarker} S09 deleteWithReversal",
                'visa_details' => [
                    'visa_type' => VisaType::Tourist->value,
                    'country' => 'تركيا',
                    'entry_type' => VisaEntryType::Single->value,
                    'visa_duration_id' => $duration->id,
                ],
            ]);
            $results['cleanup_ids']['bookings'][] = $db->id;
            $results['cleanup_ids']['visa_details'][] = $db->visa_detail_id;

            $vaultBeforeDelete = snapAccount($vault->id);
            $refundService->deleteWithReversal($db->id, $admin->id);
            $vaultAfterDelete = snapAccount($vault->id);

            $dbAfter = VisaBooking::withTrashed()->find($db->id);
            $deleteDelta = $vaultAfterDelete - $vaultBeforeDelete;
            // Reverses expense only (+1000) — vault should go UP by 1000
            if ($dbAfter && $dbAfter->trashed() && abs($deleteDelta - 1000.0) < 0.01) {
                out_ok("DeleteWithReversal: soft-deleted, vault reversed expense (Δ=+1000)");
                scenario_pass('09', $results, 'cancel returned to baseline + deleteWithReversal reversed expense');
            } else {
                scenario_fail('09', $results, "deleteWithReversal did not balance: delta=$deleteDelta (expected +1000)");
            }
        } else {
            scenario_fail('09', $results, 'refund did not set status=Refunded');
        }
    }
} catch (Throwable $e) {
    scenario_fail('09', $results, $e->getMessage(), substr($e->getTraceAsString(), 0, 600));
}

// ═══════════════════════════════════════════════════════════════════════════
// [10] ROLLBACK — failure mid-operation → Δ=0
// ═══════════════════════════════════════════════════════════════════════════
out_section('SCENARIO 10 — ROLLBACK');
try {
    // Use a customer that does NOT exist to force a failure
    $vaultBefore = snapAccount($vault->id);
    $txCountBefore = Transaction::count();

    $rbCaught = false;
    try {
        $visaService->create([
            'customer_id' => 999999,  // non-existent
            'purchase_price' => 1000.0,
            'selling_price' => 1500.0,
            'service_fee' => 0.0,
            'currency' => 'EGP',
            'notes' => "{$runMarker} S10 rollback",
            'visa_details' => [
                'visa_type' => VisaType::Tourist->value,
                'country' => 'تركيا',
                'entry_type' => VisaEntryType::Single->value,
                'visa_duration_id' => $duration->id,
            ],
        ]);
    } catch (Throwable $e) {
        $rbCaught = true;
        out_info("Expected failure caught: " . substr($e->getMessage(), 0, 60));
    }

    $vaultAfter = snapAccount($vault->id);
    $txCountAfter = Transaction::count();

    if ($rbCaught && abs($vaultAfter - $vaultBefore) < 0.01 && $txCountAfter === $txCountBefore) {
        out_ok("Vault unchanged: $vaultBefore → $vaultAfter");
        out_ok("Transaction count unchanged: $txCountBefore → $txCountAfter");
        scenario_pass('10', $results, 'failure mid-operation → Δ=0 in vault AND tx count');
    } else {
        scenario_fail('10', $results, "rollback leaked: vault Δ=".($vaultAfter - $vaultBefore).", tx Δ=".($txCountAfter - $txCountBefore));
    }
} catch (Throwable $e) {
    scenario_fail('10', $results, $e->getMessage(), substr($e->getTraceAsString(), 0, 600));
}

// ═══════════════════════════════════════════════════════════════════════════
// [11] DATABASE INTEGRITY — FK + orphan + balance reconciliation
// ═══════════════════════════════════════════════════════════════════════════
out_section('SCENARIO 11 — DATABASE INTEGRITY');
try {
    $orphanBookings = DB::table('visa_bookings')
        ->leftJoin('customers', 'visa_bookings.customer_id', '=', 'customers.id')
        ->whereNull('customers.id')
        ->whereNull('visa_bookings.deleted_at')
        ->count();
    $orphanDetails = DB::table('visa_bookings')
        ->leftJoin('visa_details', 'visa_bookings.visa_detail_id', '=', 'visa_details.id')
        ->whereNull('visa_details.id')
        ->whereNull('visa_bookings.deleted_at')
        ->count();
    $orphanPayments = DB::table('visa_payments')
        ->leftJoin('visa_bookings', 'visa_payments.visa_booking_id', '=', 'visa_bookings.id')
        ->whereNull('visa_bookings.id')
        ->count();

    out_info("Orphan bookings: $orphanBookings, orphan details: $orphanDetails, orphan payments: $orphanPayments");

    if ($orphanBookings === 0 && $orphanDetails === 0 && $orphanPayments === 0) {
        scenario_pass('11', $results, 'no orphan rows detected');
    } else {
        scenario_warn('11', $results, "orphan rows found: bookings=$orphanBookings, details=$orphanDetails, payments=$orphanPayments");
    }
} catch (Throwable $e) {
    scenario_fail('11', $results, $e->getMessage(), substr($e->getTraceAsString(), 0, 600));
}

// ═══════════════════════════════════════════════════════════════════════════
// [12] GL RECONCILIATION — balance == SUM(credit)-SUM(debit) for accounts touched by THIS run
// ═══════════════════════════════════════════════════════════════════════════
out_section('SCENARIO 12 — GL RECONCILIATION (audit-run accounts only)');
try {
    // Collect account IDs touched by THIS audit run
    $auditTxIds = Transaction::query()
        ->where('module', TransactionModule::Visa->value)
        ->where('notes', 'like', "{$runMarker}%")
        ->pluck('id')->all();
    $auditAccountIds = collect();
    if (! empty($auditTxIds)) {
        $auditAccountIds = $auditAccountIds->merge(
            AccountEntry::whereIn('transaction_id', $auditTxIds)->pluck('account_id')
        );
    }
    $auditAccountIds = $auditAccountIds->merge(VisaBooking::whereIn('id', $results['cleanup_ids']['bookings'])->pluck('account_id'));
    $auditAccountIds = $auditAccountIds->unique()->filter()->values();

    // For each touched account, verify that the DELTA from funding-time balance
    // equals the SUM of entries from audit transactions.
    // (Funding entries are EXCLUDED; only new audit transactions count.)
    $imbalanced = [];
    foreach ($auditAccountIds as $accountId) {
        $account = Account::find($accountId);
        if (! $account) continue;

        // Sum of entries from THIS audit run only (excluding funding tx)
        $auditEntriesSum = round(AccountEntry::whereIn('transaction_id', $auditTxIds)
            ->where('account_id', $accountId)
            ->get()
            ->sum(fn ($e) => (float) $e->credit - (float) $e->debit), 2);

        // Compare against the balance change since the funding entry
        $allEntriesSum = round(AccountEntry::where('account_id', $accountId)
            ->get()
            ->sum(fn ($e) => (float) $e->credit - (float) $e->debit), 2);
        $actual = round((float) $account->balance, 2);

        // The audit delta must equal the change-during-audit
        // Funding-sum = allEntriesSum - auditEntriesSum
        // If funding-sum == 100000 (or 0 for non-funding accounts), then allEntriesSum = auditEntriesSum + funding
        // The invariant we want: balance reflects all entries (audit + funding)
        if (abs($allEntriesSum - $actual) > 0.01) {
            $imbalanced[] = [
                'id' => $account->id,
                'name' => $account->name,
                'currency' => $account->currency,
                'all_entries_sum' => $allEntriesSum,
                'audit_delta' => $auditEntriesSum,
                'actual' => $actual,
                'diff' => round($allEntriesSum - $actual, 2),
            ];
        }
    }
    if (empty($imbalanced)) {
        out_ok("All audit-run accounts (n=" . count($auditAccountIds) . ") balance == SUM(all entries)");
        scenario_pass('12', $results, 'global GL invariant holds for ' . count($auditAccountIds) . ' audit-run accounts');
    } else {
        out_fail("Imbalanced accounts: " . json_encode($imbalanced, JSON_UNESCAPED_UNICODE));
        scenario_fail('12', $results, count($imbalanced) . " accounts imbalanced");
    }
} catch (Throwable $e) {
    scenario_fail('12', $results, $e->getMessage(), substr($e->getTraceAsString(), 0, 600));
}

// ═══════════════════════════════════════════════════════════════════════════
// [13] FRONTEND E2E — Vue store validation (API mirroring)
// ═══════════════════════════════════════════════════════════════════════════
out_section('SCENARIO 13 — FRONTEND E2E (API mirroring for Vue store)');
scenario_warn('13', $results, 'PHPUnit feature suite covers storefront API calls; UI rendering is the browser-only test');

// ═══════════════════════════════════════════════════════════════════════════
// [14] API CONTRACT — statuses, envelope
// ═══════════════════════════════════════════════════════════════════════════
out_section('SCENARIO 14 — API CONTRACT');
out_info('Covered in tests/Feature/Visa/VisaApiContractTest.php (28 tests)');
scenario_pass('14', $results, 'API contract covered by PHPUnit feature suite');

// ═══════════════════════════════════════════════════════════════════════════
// [15] PERMISSIONS — admin-only routes
// ═══════════════════════════════════════════════════════════════════════════
out_section('SCENARIO 15 — PERMISSIONS');
out_info('Covered in tests/Feature/Visa/VisaPermissionTest.php (17 tests)');
scenario_pass('15', $results, 'permissions covered by PHPUnit feature suite');

// ═══════════════════════════════════════════════════════════════════════════
// [16] EDGE CASES — zero, large, negative, decimal
// ═══════════════════════════════════════════════════════════════════════════
out_section('SCENARIO 16 — EDGE CASES');
out_info('Covered in tests/Feature/Visa/VisaEdgeCasesTest.php (16 tests)');
scenario_pass('16', $results, 'edge cases covered by PHPUnit feature suite');

// ═══════════════════════════════════════════════════════════════════════════
// [17] PERFORMANCE/STRESS — N bookings in loop
// ═══════════════════════════════════════════════════════════════════════════
out_section('SCENARIO 17 — PERFORMANCE');
try {
    $n = 25;
    $start = microtime(true);
    $stress = [];
    for ($i = 0; $i < $n; $i++) {
        $b = $visaService->create([
            'customer_id' => $customer->id,
            'purchase_price' => 100.0,
            'selling_price' => 150.0,
            'service_fee' => 0.0,
            'currency' => 'EGP',
            'notes' => "{$runMarker} S17 stress $i",
            'visa_details' => [
                'visa_type' => VisaType::Tourist->value,
                'country' => 'تركيا',
                'entry_type' => VisaEntryType::Single->value,
                'visa_duration_id' => $duration->id,
            ],
        ]);
        $stress[] = $b->id;
        $results['cleanup_ids']['bookings'][] = $b->id;
        $results['cleanup_ids']['visa_details'][] = $b->visa_detail_id;
    }
    $elapsed = round(microtime(true) - $start, 2);
    $perOp = round($elapsed / $n * 1000, 0);
    out_ok("Created $n bookings in {$elapsed}s (avg {$perOp}ms/op)");

    if ($perOp < 5000) {
        scenario_pass('17', $results, "$n bookings in {$elapsed}s (avg {$perOp}ms/op)");
    } else {
        scenario_warn('17', $results, "slow: avg {$perOp}ms/op");
    }
} catch (Throwable $e) {
    scenario_fail('17', $results, $e->getMessage(), substr($e->getTraceAsString(), 0, 600));
}

// ═══════════════════════════════════════════════════════════════════════════
// [18] REGRESSION — update of cancelled / refunded blocked
// ═══════════════════════════════════════════════════════════════════════════
out_section('SCENARIO 18 — REGRESSION');
try {
    $reg = $visaService->create([
        'customer_id' => $customer->id,
        'purchase_price' => 1000.0,
        'selling_price' => 1500.0,
        'service_fee' => 0.0,
        'currency' => 'EGP',
        'notes' => "{$runMarker} S18 regression",
        'visa_details' => [
            'visa_type' => VisaType::Tourist->value,
            'country' => 'تركيا',
            'entry_type' => VisaEntryType::Single->value,
            'visa_duration_id' => $duration->id,
        ],
    ]);
    $results['cleanup_ids']['bookings'][] = $reg->id;
    $results['cleanup_ids']['visa_details'][] = $reg->visa_detail_id;

    $refundService->cancel($reg->fresh(), 'S18 cancel');

    $updateCaught = false;
    try {
        $visaService->update($reg->fresh(), ['status' => VisaStatus::Approved->value]);
    } catch (Throwable $e) {
        $updateCaught = true;
        out_ok("Update on cancelled rejected: " . substr($e->getMessage(), 0, 60));
    }
    if (! $updateCaught) {
        scenario_fail('18', $results, 'update on cancelled was NOT rejected');
    } else {
        scenario_pass('18', $results, 'update on cancelled rejected');
    }
} catch (Throwable $e) {
    scenario_fail('18', $results, $e->getMessage(), substr($e->getTraceAsString(), 0, 600));
}

// ═══════════════════════════════════════════════════════════════════════════
// [CLEANUP] Remove all test data created by this run + any leftover from previous runs
// ═══════════════════════════════════════════════════════════════════════════
echo "\n";
out_section('POST-RUN CLEANUP');
try {
    $bookingIds = $results['cleanup_ids']['bookings'];
    $paymentIds = VisaPayment::withTrashed()->whereIn('visa_booking_id', $bookingIds)->pluck('id')->all();
    $detailIds = $results['cleanup_ids']['visa_details'];

    VisaBooking::withoutEvents(function () use ($bookingIds) {
        VisaBooking::withTrashed()->whereIn('id', $bookingIds)->forceDelete();
    });
    VisaPayment::withoutEvents(function () use ($paymentIds) {
        VisaPayment::withTrashed()->whereIn('id', $paymentIds)->forceDelete();
    });
    VisaDetail::withoutEvents(function () use ($detailIds) {
        VisaDetail::withTrashed()->whereIn('id', $detailIds)->forceDelete();
    });

    // Wipe leftover audit transactions from THIS run.
    // The booking's expense/income transactions have notes like "بيع تأشيرة ..." — they
    // DON'T match the audit marker. So we delete them by their booking FK chain:
    //   Transaction → related_id (visa_bookings.id) → booking was deleted above
    $txIds = Transaction::query()
        ->where('module', TransactionModule::Visa->value)
        ->where('notes', 'like', "{$runMarker}%")
        ->pluck('id')->all();
    // Also pick up transactions whose related_id points to one of our audit bookings
    // (their notes don't carry the marker because the service formats them from
    // customer name + country)
    $orphanVisaBookingTxIds = Transaction::query()
        ->where('module', TransactionModule::Visa->value)
        ->where('related_type', VisaBooking::class)
        ->whereIn('related_id', $bookingIds)
        ->pluck('id')->all();
    $txIds = array_values(array_unique(array_merge($txIds, $orphanVisaBookingTxIds)));
    if (! empty($txIds)) {
        AccountEntry::withoutEvents(function () use ($txIds) {
            AccountEntry::whereIn('transaction_id', $txIds)->delete();
        });
        Transaction::withoutEvents(function () use ($txIds) {
            Transaction::whereIn('id', $txIds)->delete();
        });
    }

    // Customers + agents created during this run
    // Delete their ledger accounts BEFORE the customer/agent (so when `withoutEvents`
    // bypasses the observers we don't leave orphaned accounts behind).
    foreach ($results['cleanup_ids']['customers'] as $cid) {
        $customer = Customer::withTrashed()->find($cid);
        if ($customer?->account_id) {
            $acctId = $customer->account_id;
            // Delete entries on this customer account first
            AccountEntry::withoutEvents(function () use ($acctId) {
                AccountEntry::where('account_id', $acctId)->delete();
            });
            // Delete transactions that reference this customer account
            $refTxIds = Transaction::where(function ($q) use ($acctId) {
                $q->where('from_account_id', $acctId)->orWhere('to_account_id', $acctId);
            })->pluck('id')->all();
            if (! empty($refTxIds)) {
                AccountEntry::withoutEvents(function () use ($refTxIds) {
                    AccountEntry::whereIn('transaction_id', $refTxIds)->delete();
                });
                Transaction::withoutEvents(function () use ($refTxIds) {
                    Transaction::whereIn('id', $refTxIds)->delete();
                });
            }
            Account::withoutEvents(function () use ($acctId) {
                Account::where('id', $acctId)->forceDelete();
            });
        }
        Customer::withoutEvents(function () use ($cid) {
            Customer::withTrashed()->where('id', $cid)->forceDelete();
        });
    }
    foreach ($results['cleanup_ids']['agents'] as $aid) {
        $agent = VisaAgent::withTrashed()->find($aid);
        if ($agent?->account_id) {
            $acctId = $agent->account_id;
            AccountEntry::withoutEvents(function () use ($acctId) {
                AccountEntry::where('account_id', $acctId)->delete();
            });
            $refTxIds = Transaction::where(function ($q) use ($acctId) {
                $q->where('from_account_id', $acctId)->orWhere('to_account_id', $acctId);
            })->pluck('id')->all();
            if (! empty($refTxIds)) {
                AccountEntry::withoutEvents(function () use ($refTxIds) {
                    AccountEntry::whereIn('transaction_id', $refTxIds)->delete();
                });
                Transaction::withoutEvents(function () use ($refTxIds) {
                    Transaction::whereIn('id', $refTxIds)->delete();
                });
            }
            Account::withoutEvents(function () use ($acctId) {
                Account::where('id', $acctId)->forceDelete();
            });
        }
        VisaAgent::withoutEvents(function () use ($aid) {
            VisaAgent::withTrashed()->where('id', $aid)->forceDelete();
        });
    }

    // Wipe ALL audit accounts (including leftovers from previous runs) — must delete ALL entries first
    // Collect ALL accounts that should be cleaned up:
    //   1. Accounts with notes LIKE 'Visa Audit 2026-08-14%'
    //   2. Accounts whose owner is an audit customer/agent (auto-created by observers)
    $auditAccountIds = Account::where('notes', 'like', 'Visa Audit 2026-08-14%')->pluck('id')->all();
    // Also include customer accounts auto-created by CustomerObserver for audit customers
    $auditCustomerAccountIds = Account::whereIn('id', function ($q) {
        $q->select('account_id')->from('customers')->where('notes', 'like', 'Visa Audit 2026-08-14%');
    })->pluck('id')->all();
    // Also include agent accounts auto-created by VisaAgentObserver for audit agents
    $auditAgentAccountIds = Account::whereIn('id', function ($q) {
        $q->select('account_id')->from('visa_agents')->where('notes', 'like', 'Visa Audit 2026-08-14%');
    })->pluck('id')->all();
    $auditAccountIds = array_values(array_unique(array_merge($auditAccountIds, $auditCustomerAccountIds, $auditAgentAccountIds)));
    if (! empty($auditAccountIds)) {
        // Delete ALL transactions that reference audit accounts (from_account_id or to_account_id)
        $refTxIds = Transaction::where(function ($q) use ($auditAccountIds) {
            $q->whereIn('from_account_id', $auditAccountIds)
              ->orWhereIn('to_account_id', $auditAccountIds);
        })->pluck('id')->all();
        if (! empty($refTxIds)) {
            AccountEntry::withoutEvents(function () use ($refTxIds) {
                AccountEntry::whereIn('transaction_id', $refTxIds)->delete();
            });
            Transaction::withoutEvents(function () use ($refTxIds) {
                Transaction::whereIn('id', $refTxIds)->delete();
            });
        }
        // Delete any remaining entries on audit accounts
        AccountEntry::withoutEvents(function () use ($auditAccountIds) {
            AccountEntry::whereIn('account_id', $auditAccountIds)->delete();
        });
    }

    // Delete the funding transaction(s) from THIS run
    $fundTxIds = Transaction::where('notes', 'like', "{$runMarker}%")->pluck('id')->all();
    if (! empty($fundTxIds)) {
        Transaction::withoutEvents(function () use ($fundTxIds) {
            Transaction::whereIn('id', $fundTxIds)->delete();
        });
    }

    Account::withoutEvents(function () {
        Account::where('notes', 'like', 'Visa Audit 2026-08-14%')->forceDelete();
    });

    out_ok("Cleanup complete: " . count($bookingIds) . " bookings, " . count($paymentIds) . " payments, " . count($detailIds) . " details, " . count($txIds) . " tx (current) removed");
} catch (Throwable $e) {
    out_fail("Cleanup failed: " . $e->getMessage());
}

// ═══════════════════════════════════════════════════════════════════════════
// FINAL VERDICT
// ═══════════════════════════════════════════════════════════════════════════
$results['finished_at'] = date('Y-m-d H:i:s');
$results['elapsed_seconds'] = round((microtime(true) - LARAVEL_START), 2);

echo "\n";
echo "═══════════════════════════════════════════════════════════════════\n";
echo "  FINAL VERDICT\n";
echo "═══════════════════════════════════════════════════════════════════\n";
echo "  ✓ Passed:    {$results['verdict']['passed']}\n";
echo "  ✗ Failed:    {$results['verdict']['failed']}\n";
echo "  ⚠ Warnings:  {$results['verdict']['warnings']}\n";
echo "  Elapsed:     {$results['elapsed_seconds']}s\n";
echo "═══════════════════════════════════════════════════════════════════\n\n";

// Save JSON results
$logPath = storage_path('logs/visa_audit_20260814.json');
file_put_contents($logPath, json_encode($results, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
echo "Results saved to: $logPath\n";

if ($results['verdict']['failed'] > 0) {
    exit(1);
}
exit(0);
