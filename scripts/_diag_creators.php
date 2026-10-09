<?php

/**
 * READ-ONLY: WHO CREATED the transactions in office liquidity accounts?
 * ────────────────────────────────────────────────────────────────────────
 * Detects:
 *   - All users who created transactions affecting office cashbox/bank/wallet
 *   - All IPs that hit those endpoints
 *   - (User, IP) combinations to find suspicious patterns
 *   - Possible "test" users (by email pattern)
 *   - Possible "test" IPs (loopback, private ranges)
 *
 * Usage on server:
 *   cd /var/www/safarakealayna
 *   php _diag_creators.php
 */

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

// Get the set of office liquidity account IDs
$accountIds = DB::table('accounts')
    ->whereIn('type', ['cashbox', 'bank', 'wallet'])
    ->where('module_type', 'office')
    ->whereNull('deleted_at')
    ->pluck('id')
    ->toArray();

if (empty($accountIds)) {
    echo 'No office liquidity accounts found!'.PHP_EOL;
    exit(1);
}

echo str_repeat('═', 100).PHP_EOL;
echo ' WHO CREATED the transactions in office liquidity accounts?'.PHP_EOL;
echo ' Account IDs: '.implode(', ', $accountIds).PHP_EOL;
echo str_repeat('═', 100).PHP_EOL.PHP_EOL;

// =====================================================================================
// 1) USERS who created transactions affecting office accounts
// =====================================================================================
echo str_repeat('─', 100).PHP_EOL;
echo ' 1) USERS (count of transactions touching office liquidity accounts)'.PHP_EOL;
echo str_repeat('─', 100).PHP_EOL;

$userStats = DB::table('transactions as t')
    ->join('users as u', 'u.id', '=', 't.created_by')
    ->where(function ($q) use ($accountIds) {
        $q->whereIn('t.from_account_id', $accountIds)
            ->orWhereIn('t.to_account_id', $accountIds);
    })
    ->groupBy('u.id', 'u.name', 'u.email')
    ->select(
        'u.id', 'u.name', 'u.email',
        DB::raw('COUNT(*) as total_txns'),
        DB::raw('SUM(CASE WHEN t.module = "general" THEN 1 ELSE 0 END) as general_module_count'),
        DB::raw('SUM(CASE WHEN t.notes IS NULL OR t.notes = "" THEN 1 ELSE 0 END) as empty_notes_count'),
        DB::raw('SUM(t.amount) as total_amount')
    )
    ->orderByDesc('total_txns')
    ->get();

printf("%-5s | %-30s | %-40s | %8s | %8s | %8s | %12s\n",
    'ID', 'Name', 'Email', 'Txns', 'General', 'NoNotes', 'Amount');
echo str_repeat('─', 100).PHP_EOL;

$isTestUser = function ($email, $name) {
    $patterns = ['test', 'demo', 'fake', 'temp', 'admin@yourdomain', 'example.com', 'sample'];
    $haystack = strtolower(($email ?? '').' '.($name ?? ''));
    foreach ($patterns as $p) {
        if (str_contains($haystack, $p)) {
            return true;
        }
    }

    return false;
};

foreach ($userStats as $u) {
    $isTest = $isTestUser($u->email, $u->name) ? '🧪 TEST?' : '';
    printf("%-5d | %-30s | %-40s | %8d | %8d | %8d | %12s %s\n",
        $u->id,
        mb_substr($u->name ?? 'NULL', 0, 30),
        mb_substr($u->email ?? 'NULL', 0, 40),
        $u->total_txns,
        $u->general_module_count,
        $u->empty_notes_count,
        number_format((float) $u->total_amount, 2),
        $isTest
    );
}

// =====================================================================================
// 2) IPs that hit the endpoints
// =====================================================================================
echo PHP_EOL.str_repeat('─', 100).PHP_EOL;
echo ' 2) IPs (count of transactions by IP)'.PHP_EOL;
echo str_repeat('─', 100).PHP_EOL;

$ipStats = DB::table('transactions as t')
    ->where(function ($q) use ($accountIds) {
        $q->whereIn('t.from_account_id', $accountIds)
            ->orWhereIn('t.to_account_id', $accountIds);
    })
    ->whereNotNull('t.client_ip')
    ->groupBy('t.client_ip')
    ->select(
        't.client_ip',
        DB::raw('COUNT(*) as total_txns'),
        DB::raw('SUM(CASE WHEN t.module = "general" THEN 1 ELSE 0 END) as general_count'),
        DB::raw('SUM(CASE WHEN t.notes IS NULL OR t.notes = "" THEN 1 ELSE 0 END) as empty_notes_count')
    )
    ->orderByDesc('total_txns')
    ->get();

printf("%-20s | %8s | %8s | %8s | %s\n", 'IP', 'Txns', 'General', 'NoNotes', 'Likely Origin');
echo str_repeat('─', 100).PHP_EOL;

$ipOrigin = function ($ip) {
    if (! $ip) {
        return '-';
    }
    if (in_array($ip, ['127.0.0.1', '::1'])) {
        return '🧪 localhost (test?)';
    }
    if (str_starts_with($ip, '192.168.') || str_starts_with($ip, '10.') || str_starts_with($ip, '172.16.')) {
        return '🏠 private network';
    }
    // TE Data Egypt
    if (str_starts_with($ip, '102.186.') || str_starts_with($ip, '156.198.') || str_starts_with($ip, '197.32.')) {
        return '🇪🇬 TE Data Egypt';
    }
    // Vodafone Egypt
    if (str_starts_with($ip, '105.34.') || str_starts_with($ip, '105.35.') || str_starts_with($ip, '196.218.')) {
        return '🇪🇬 Vodafone Egypt';
    }
    // Orange Egypt
    if (str_starts_with($ip, '41.32.') || str_starts_with($ip, '41.33.')) {
        return '🇪🇬 Orange Egypt';
    }
    // WE Egypt
    if (str_starts_with($ip, '156.208.') || str_starts_with($ip, '156.209.')) {
        return '🇪🇬 WE Egypt';
    }

    // Foreign
    return '🌍 foreign / unknown';
};

foreach ($ipStats as $ip) {
    printf("%-20s | %8d | %8d | %8d | %s\n",
        $ip->client_ip, $ip->total_txns, $ip->general_count, $ip->empty_notes_count,
        $ipOrigin($ip->client_ip)
    );
}

// =====================================================================================
// 3) Cross-tab: USER × IP combinations
// =====================================================================================
echo PHP_EOL.str_repeat('─', 100).PHP_EOL;
echo ' 3) USER × IP combinations'.PHP_EOL;
echo str_repeat('─', 100).PHP_EOL;

$crossStats = DB::table('transactions as t')
    ->join('users as u', 'u.id', '=', 't.created_by')
    ->where(function ($q) use ($accountIds) {
        $q->whereIn('t.from_account_id', $accountIds)
            ->orWhereIn('t.to_account_id', $accountIds);
    })
    ->whereNotNull('t.client_ip')
    ->groupBy('u.id', 'u.name', 'u.email', 't.client_ip')
    ->select(
        'u.id', 'u.name', 'u.email', 't.client_ip',
        DB::raw('COUNT(*) as total_txns'),
        DB::raw('MIN(t.created_at) as first_txn'),
        DB::raw('MAX(t.created_at) as last_txn')
    )
    ->orderBy('u.id')->orderBy('t.client_ip')
    ->get();

printf("%-5s | %-25s | %-20s | %8s | %-19s | %-19s\n",
    'UID', 'User Name', 'IP', 'Txns', 'First Txn', 'Last Txn');
echo str_repeat('─', 100).PHP_EOL;
foreach ($crossStats as $c) {
    printf("%-5d | %-25s | %-20s | %8d | %-19s | %-19s\n",
        $c->id, mb_substr($c->name ?? '', 0, 25), $c->client_ip,
        $c->total_txns, $c->first_txn, $c->last_txn
    );
}

// =====================================================================================
// 4) List all users in the system (to identify test users)
// =====================================================================================
echo PHP_EOL.str_repeat('─', 100).PHP_EOL;
echo ' 4) ALL USERS in the system'.PHP_EOL;
echo str_repeat('─', 100).PHP_EOL;

$allUsers = DB::table('users')
    ->orderBy('id')
    ->get(['id', 'name', 'email', 'is_active', 'created_at']);

printf("%-5s | %-30s | %-40s | %-8s | %s\n",
    'ID', 'Name', 'Email', 'Active', 'Likely?');
echo str_repeat('─', 100).PHP_EOL;
foreach ($allUsers as $u) {
    $isTest = $isTestUser($u->email, $u->name);
    $indicator = $isTest ? '🧪 TEST?' : ($u->id == 1 ? '🔑 SUPER' : '👤 USER');
    printf("%-5d | %-30s | %-40s | %-8s | %s\n",
        $u->id,
        mb_substr($u->name ?? 'NULL', 0, 30),
        mb_substr($u->email ?? 'NULL', 0, 40),
        $u->is_active ? 'YES' : 'NO',
        $indicator
    );
}

// =====================================================================================
// 5) Entries with NO notes — to see if any are from test users
// =====================================================================================
echo PHP_EOL.str_repeat('─', 100).PHP_EOL;
echo ' 5) ENTRIES WITH NO NOTES — by user'.PHP_EOL;
echo str_repeat('─', 100).PHP_EOL;

$noNotesByUser = DB::table('account_entries as e')
    ->join('transactions as t', 't.id', '=', 'e.transaction_id')
    ->join('users as u', 'u.id', '=', 't.created_by')
    ->whereIn('e.account_id', $accountIds)
    ->where(function ($q) {
        $q->whereNull('t.notes')->orWhere('t.notes', '');
    })
    ->groupBy('u.id', 'u.name', 'u.email')
    ->select(
        'u.id', 'u.name', 'u.email',
        DB::raw('COUNT(*) as no_notes_count'),
        DB::raw('SUM(e.debit) as total_debit'),
        DB::raw('SUM(e.credit) as total_credit')
    )
    ->orderByDesc('no_notes_count')
    ->get();

if ($noNotesByUser->isEmpty()) {
    echo '  ✅ No transactions with empty notes found!'.PHP_EOL;
} else {
    printf("%-5s | %-30s | %-40s | %8s | %12s | %12s\n",
        'ID', 'Name', 'Email', 'Count', 'Total Dr', 'Total Cr');
    echo str_repeat('─', 100).PHP_EOL;
    foreach ($noNotesByUser as $u) {
        $isTest = $isTestUser($u->email, $u->name) ? '🧪 TEST?' : '';
        printf("%-5d | %-30s | %-40s | %8d | %12s | %12s %s\n",
            $u->id, mb_substr($u->name ?? '', 0, 30), mb_substr($u->email ?? '', 0, 40),
            $u->no_notes_count,
            number_format((float) $u->no_notes_count, 0),
            number_format((float) $u->total_debit, 2),
            number_format((float) $u->total_credit, 2),
            $isTest
        );
    }
}

echo PHP_EOL.str_repeat('═', 100).PHP_EOL;
echo ' DONE — Review the output above to identify test users / test data.'.PHP_EOL;
echo " Look for: 🧪 TEST? markers, suspicious IPs, users with 'test' in email/name.".PHP_EOL;
echo str_repeat('═', 100).PHP_EOL;
