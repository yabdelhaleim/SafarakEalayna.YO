<?php
/**
 * ════════════════════════════════════════════════════════════════════════════
 * Office Liquidity Snapshot — Collect & Save as JSON
 * ════════════════════════════════════════════════════════════════════════════
 *
 * النسخة اللي بتشتغل على السيرفر مباشرة:
 *   - بيشغّل نفس الـ queries بتاعت office_liquidity_snapshot.sql
 *   - بيحفظ النتيجة كلها في ملف JSON واحد منظم
 *   - سهل ترفعه أو تنسخه وتبعتلي محتواه
 *
 * التشغيل على السيرفر:
 *   cd /var/www/safarakealayna
 *   php scripts/office_liquidity_snapshot_collect.php
 *
 * المخرجات:
 *   storage/logs/office_liquidity_snapshot_data.json
 *   storage/logs/office_liquidity_snapshot_data.txt  (human-readable)
 *
 * ابعت لي محتويات ملف JSON عشان أبدأ تحليل الفروقات.
 */

if (!defined('LARAVEL_START')) {
    define('LARAVEL_START', microtime(true));
    require __DIR__ . '/../vendor/autoload.php';
    $app = require_once __DIR__ . '/../bootstrap/app.php';
    $kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
    $kernel->bootstrap();
}

use Illuminate\Support\Facades\DB;

// ─── Banner ───
echo "\n";
echo "╔══════════════════════════════════════════════════════════════════════╗\n";
echo "║  Office Liquidity Snapshot — Collector                              ║\n";
echo "║  شغّال على السيرفر مباشرة، بيحفظ النتيجة JSON                     ║\n";
echo "╚══════════════════════════════════════════════════════════════════════╝\n";
echo "\n";

$conn = DB::connection()->getName();
$dbName = DB::connection()->getDatabaseName();
echo "    DB: $conn / $dbName\n";
echo "    Time: " . date('Y-m-d H:i:s') . "\n\n";

// ─── 1) Snapshot كامل ───
echo "    [1/5] Snapshot كامل (Q1)...\n";
$snapshot = DB::table('accounts')
    ->where(function ($q) {
        $q->whereIn('type', ['cashbox', 'bank', 'wallet'])
          ->orWhere('type', 'treasury');
    })
    ->where('module_type', 'office')
    ->whereNull('deleted_at')
    ->orderBy('type')
    ->orderBy('currency')
    ->orderBy('name')
    ->get([
        'id', 'name', 'type', 'treasury_type', 'bank_name', 'account_number',
        'currency', 'balance', 'is_active', 'is_module_vault',
        'created_at', 'updated_at',
    ])->map(fn($a) => [
        'id' => $a->id,
        'name' => $a->name,
        'type' => $a->type,
        'treasury_type' => $a->treasury_type,
        'bank_name' => $a->bank_name,
        'account_number' => $a->account_number,
        'currency' => $a->currency,
        'balance' => (float) $a->balance,
        'is_active' => (bool) $a->is_active,
        'is_module_vault' => (bool) $a->is_module_vault,
        'created_at' => $a->created_at,
        'updated_at' => $a->updated_at,
    ])->toArray();
echo "        → " . count($snapshot) . " account(s)\n";

// ─── 2) ملخص حسب النوع ───
echo "    [2/5] ملخص حسب النوع (Q2)...\n";
$byType = DB::table('accounts')
    ->where(function ($q) {
        $q->whereIn('type', ['cashbox', 'bank', 'wallet'])
          ->orWhere('type', 'treasury');
    })
    ->where('module_type', 'office')
    ->whereNull('deleted_at')
    ->groupBy('type')
    ->selectRaw('type, COUNT(*) AS cnt, SUM(CASE WHEN is_active=1 THEN 1 ELSE 0 END) AS active_cnt')
    ->get()
    ->mapWithKeys(fn($r) => [$r->type => ['count' => (int) $r->cnt, 'active' => (int) $r->active_cnt]])
    ->toArray();

// ─── 3) ملخص حسب العملة والنوع ───
echo "    [3/5] ملخص حسب العملة والنوع (Q3)...\n";
$byCurrency = DB::table('accounts')
    ->where(function ($q) {
        $q->whereIn('type', ['cashbox', 'bank', 'wallet'])
          ->orWhere('type', 'treasury');
    })
    ->where('module_type', 'office')
    ->whereNull('deleted_at')
    ->groupBy('currency', 'type')
    ->selectRaw('currency, type, COUNT(*) AS cnt, SUM(balance) AS total')
    ->get()
    ->map(fn($r) => [
        'currency' => $r->currency,
        'type' => $r->type,
        'count' => (int) $r->cnt,
        'total_stored_balance' => (float) $r->total,
    ])->toArray();

// ─── 4) أرصدة سالبة ───
echo "    [4/5] الأرصدة السالبة (Q4)...\n";
$negative = DB::table('accounts')
    ->where(function ($q) {
        $q->whereIn('type', ['cashbox', 'bank', 'wallet'])
          ->orWhere('type', 'treasury');
    })
    ->where('module_type', 'office')
    ->whereNull('deleted_at')
    ->where('balance', '<', 0)
    ->orderBy('balance')
    ->get(['id', 'name', 'type', 'currency', 'balance', 'created_at'])
    ->map(fn($r) => [
        'id' => $r->id,
        'name' => $r->name,
        'type' => $r->type,
        'currency' => $r->currency,
        'balance' => (float) $r->balance,
        'created_at' => $r->created_at,
    ])->toArray();
echo "        → " . count($negative) . " account(s) with negative balance\n";

// ─── 5) Q7: Stored vs Computed (الدرift) — الأهم ───
echo "    [5/5] Stored vs Computed من الـ ledger (Q7)...\n";
$drift = DB::table('accounts as a')
    ->leftJoin('account_entries as ae', 'ae.account_id', '=', 'a.id')
    ->where(function ($q) {
        $q->whereIn('a.type', ['cashbox', 'bank', 'wallet'])
          ->orWhere('a.type', 'treasury');
    })
    ->where('a.module_type', 'office')
    ->whereNull('a.deleted_at')
    ->groupBy('a.id', 'a.name', 'a.type', 'a.currency', 'a.balance')
    ->orderBy('a.type')
    ->orderBy('a.currency')
    ->orderBy('a.name')
    ->selectRaw('
        a.id, a.name, a.type, a.currency, a.balance AS stored_balance,
        COUNT(ae.id) AS ledger_entries_count,
        COALESCE(SUM(ae.credit), 0) - COALESCE(SUM(ae.debit), 0) AS computed_balance,
        ROUND(a.balance - (COALESCE(SUM(ae.credit), 0) - COALESCE(SUM(ae.debit), 0)), 2) AS drift
    ')
    ->get()
    ->map(function ($r) {
        $status = match (true) {
            (int) $r->ledger_entries_count === 0 => 'NO_ENTRIES',
            abs((float) $r->drift) < 0.01 => 'OK',
            abs((float) $r->drift) < 100 => 'MINOR_DRIFT',
            default => 'MAJOR_DRIFT',
        };
        return [
            'id' => (int) $r->id,
            'name' => $r->name,
            'type' => $r->type,
            'currency' => $r->currency,
            'stored_balance' => (float) $r->stored_balance,
            'computed_balance' => (float) $r->computed_balance,
            'drift' => (float) $r->drift,
            'ledger_entries_count' => (int) $r->ledger_entries_count,
            'status' => $status,
        ];
    })->toArray();

$driftSummary = [
    'total_accounts' => count($drift),
    'ok' => count(array_filter($drift, fn($d) => $d['status'] === 'OK')),
    'no_entries' => count(array_filter($drift, fn($d) => $d['status'] === 'NO_ENTRIES')),
    'minor_drift' => count(array_filter($drift, fn($d) => $d['status'] === 'MINOR_DRIFT')),
    'major_drift' => count(array_filter($drift, fn($d) => $d['status'] === 'MAJOR_DRIFT')),
];
echo "        → " . count($drift) . " account(s); ";
echo "OK={$driftSummary['ok']}, NO_ENTRIES={$driftSummary['no_entries']}, ";
echo "MINOR={$driftSummary['minor_drift']}, MAJOR={$driftSummary['major_drift']}\n";

// ─── Save JSON ───
$jsonPath = storage_path('logs/office_liquidity_snapshot_data.json');
$payload = [
    'metadata' => [
        'collected_at' => date('Y-m-d H:i:s'),
        'db_connection' => $conn,
        'db_name' => $dbName,
        'script' => 'office_liquidity_snapshot_collect.php',
    ],
    'drift_summary' => $driftSummary,
    'by_type' => $byType,
    'by_currency' => $byCurrency,
    'negative_balances' => $negative,
    'accounts_snapshot' => $snapshot,
    'drift_per_account' => $drift,
];
file_put_contents(
    $jsonPath,
    json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
);

// ─── Save TXT (human-readable summary) ───
$txtPath = storage_path('logs/office_liquidity_snapshot_data.txt');
$lines = [];
$lines[] = "═══════════════════════════════════════════════════════════════════════";
$lines[] = "Office Liquidity Snapshot — Report";
$lines[] = "Collected: " . date('Y-m-d H:i:s');
$lines[] = "DB: $conn / $dbName";
$lines[] = "═══════════════════════════════════════════════════════════════════════";
$lines[] = "";
$lines[] = "─── DRIFT SUMMARY ───";
$lines[] = "Total accounts:    " . $driftSummary['total_accounts'];
$lines[] = "OK (no drift):     " . $driftSummary['ok'];
$lines[] = "No ledger entries: " . $driftSummary['no_entries'];
$lines[] = "Minor drift (<100): " . $driftSummary['minor_drift'];
$lines[] = "Major drift (≥100): " . $driftSummary['major_drift'];
$lines[] = "";
$lines[] = "─── ACCOUNTS WITH DRIFT ───";
foreach ($drift as $d) {
    if ($d['status'] !== 'OK') {
        $lines[] = sprintf(
            "[%s] id=%d %s (%s %s): stored=%.2f, computed=%.2f, drift=%.2f, entries=%d",
            $d['status'],
            $d['id'],
            $d['name'],
            $d['type'],
            $d['currency'],
            $d['stored_balance'],
            $d['computed_balance'],
            $d['drift'],
            $d['ledger_entries_count']
        );
    }
}
file_put_contents($txtPath, implode("\n", $lines) . "\n");

// ─── Done ───
echo "\n";
echo "╔══════════════════════════════════════════════════════════════════════╗\n";
echo "║  ✅ تم!                                                            ║\n";
echo "╚══════════════════════════════════════════════════════════════════════╝\n";
echo "\n";
echo "    📄 JSON: $jsonPath\n";
echo "    📄 TXT : $txtPath\n";
echo "\n";
echo "    ابعت لي محتويات ملف JSON عشان أبدأ تحليل الفروقات.\n";
echo "    لو الملف كبير، ممكن ترفعه على موقع زي pastebin أو تنسخ القسم المهم.\n";
echo "\n";
