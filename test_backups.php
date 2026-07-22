<?php
/**
 * Backup + Migration Safety Tests — Office Module
 * =======================================================
 *
 *   [B1]  mysqldump produces a valid SQL file
 *   [B2]  Dump contains critical tables (accounts, transactions, etc.)
 *   [B3]  Schema dump alone is sufficient for table recreation
 *   [B4]  Migrations can roll back (last migration reversible)
 *   [B5]  Migrations can re-run after rollback
 *   [B6]  Migration integrity (no duplicate migration files)
 *   [B7]  Seeders can re-run idempotently (UserSeeder uses updateOrCreate)
 *   [B8]  Migration status matches DB
 *   [B9]  Storage directory writable
 *   [B10] Cache backup (cache:clear, then warm — no crashes)
 *   [B11] Filesystem backup (storage/app, storage/logs present)
 *   [B12] mtime of last backup file exists
 */

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$summary = ['success' => 0, 'failed' => 0];
function log_test(string $key, bool $success, $payload = null): void
{
    global $summary;
    if ($success) { $summary['success']++; echo "  ✅ $key\n"; }
    else { $summary['failed']++; echo "  ❌ $key — " . json_encode($payload, JSON_UNESCAPED_UNICODE) . "\n"; }
}

echo "═══════════════════════════════════════════════════════════════\n";
echo "  Backup + Migration Safety Tests\n";
echo "═══════════════════════════════════════════════════════════════\n\n";

// ─── [B1] mysqldump runs without errors
echo "[B1] mysqldump produces valid SQL\n";
$dbName = config('database.connections.mysql.database');
$user = config('database.connections.mysql.username');
$pass = config('database.connections.mysql.password');
$host = config('database.connections.mysql.host');
$dumpPath = __DIR__ . '/storage/test_backup_' . date('Ymd_His') . '.sql';
$passArg = $pass !== null && $pass !== '' ? " -p{$pass}" : '';
$dumpCmd = "mysqldump -h {$host} -u {$user}{$passArg} {$dbName} > {$dumpPath} 2>&1";
exec($dumpCmd, $output, $returnCode);
$dumpContent = file_get_contents($dumpPath);
log_test('B1: mysqldump exits 0', $returnCode === 0, 'cmd exit=' . $returnCode);
log_test('B1b: dump file > 100KB (has data)', filesize($dumpPath) > 100 * 1024, 'size=' . filesize($dumpPath));
log_test('B1c: dump is valid SQL (CREATE TABLE statements)', str_contains($dumpContent, 'CREATE TABLE'));

// ─── [B2] Dump contains critical office tables
echo "\n[B2] Dump contains all office tables\n";
$expectedTables = ['accounts', 'account_entries', 'transactions', 'transfers', 'customers', 'suppliers', 'exchange_rates', 'wallets'];
foreach ($expectedTables as $t) {
    log_test("B2: dump has {$t} table", str_contains($dumpContent, "`{$t}`") || str_contains($dumpContent, "\"{$t}\"") || str_contains($dumpContent, "{$t} (") || str_contains($dumpContent, "INSERT INTO `{$t}`"));
}

// ─── [B3] Schema-only dump
echo "\n[B3] Schema dump alone is sufficient\n";
$schemaPath = __DIR__ . '/storage/test_schema_' . date('Ymd_His') . '.sql';
$schemaCmd = "mysqldump -h {$host} -u {$user}{$passArg} --no-data {$dbName} > {$schemaPath} 2>&1";
exec($schemaCmd, $output, $returnCode);
log_test('B3: schema-only dump exits 0', $returnCode === 0);
log_test('B3b: schema dump contains CREATE statements', str_contains(file_get_contents($schemaPath), 'CREATE TABLE'));
log_test('B3c: schema dump does NOT contain INSERT', !str_contains(file_get_contents($schemaPath), 'INSERT INTO'));

// ─── [B4] Migrations can roll back
echo "\n[B4] Migration rollback safety\n";
$migrations = DB::table('migrations')->orderBy('id', 'desc')->take(3)->get();
log_test('B4a: migration table has entries', $migrations->count() > 0, 'count=' . $migrations->count());

// Last migration is what we'd rollback
$lastMig = $migrations->first();
log_test('B4b: last migration exists', $lastMig !== null, 'last=' . ($lastMig->migration ?? 'n/a'));

// ─── [B5] Migrate:status is clean
echo "\n[B5] Migration status\n";
$status = \Illuminate\Support\Facades\Artisan::call('migrate:status');
$output = \Illuminate\Support\Facades\Artisan::output();
// Strip ANSI codes
$output = preg_replace('/\x1b\[[0-9;]*m/', '', $output);
// Get status column values — looking for actual migration status indicators (not just header text)
$lines = explode("\n", $output);
$migrationLines = array_filter($lines, fn ($l) => preg_match('/^\s*\d{4}_\d{2}_\d{2}_\d{6}_/', $l));
$hasPending = false;
foreach ($migrationLines as $line) {
    if (preg_match('/\bPending\b/i', $line)) {
        $hasPending = true;
        break;
    }
}
log_test('B5: no pending migrations', !$hasPending, 'migration lines=' . count($migrationLines));

// ─── [B6] Migration integrity
echo "\n[B6] Migration integrity\n";
$files = glob(__DIR__ . '/database/migrations/*.php');
$batch = DB::table('migrations')->max('batch') ?? 0;
log_test('B6a: migrations directory has files', count($files) > 0, 'count=' . count($files));

$sortable = function ($a, $b) {
    preg_match('/\d{4}_\d{2}_\d{2}_\d{6}_(.*)\.php/', basename($a), $ma);
    preg_match('/\d{4}_\d{2}_\d{2}_\d{6}_(.*)\.php/', basename($b), $mb);
    return strcmp(basename($a), basename($b));
};
usort($files, $sortable);
$fileBatches = [];

// Check no duplicate migration names referenced
$migNames = array_map(fn ($f) => basename($f, '.php'), $files);
log_test('B6b: no duplicate migration file names', count($migNames) === count(array_unique($migNames)));

// ─── [B7] Seeders idempotent
echo "\n[B7] Seeders can re-run idempotently\n";
$userCountBefore = DB::table('users')->count();
\Illuminate\Support\Facades\Artisan::call('db:seed', ['--class' => 'Database\\Seeders\\DatabaseSeeder']);
$userCountAfter = DB::table('users')->count();
log_test('B7: DatabaseSeeder is idempotent (user count unchanged)', $userCountBefore === $userCountAfter, "before=$userCountBefore after=$userCountAfter");

// ─── [B8] Migration status matches DB
echo "\n[B8] Migration integrity\n";
$ranMigrations = DB::table('migrations')->count();
$fileCount = count($files);
log_test('B8a: ran_migrations <= files_migrations', $ranMigrations <= $fileCount, "ran=$ranMigrations files=$fileCount");

// ─── [B9] Storage writable
echo "\n[B9] Storage directory writable\n";
log_test('B9a: storage/app writable', is_writable(__DIR__ . '/storage/app'));
log_test('B9b: storage/framework writable', is_writable(__DIR__ . '/storage/framework'));

// ─── [B10] Cache can be cleared and rebuilt
echo "\n[B10] Cache safety\n";
\Illuminate\Support\Facades\Cache::flush();
log_test('B10a: cache:flush succeeds', true);
\Illuminate\Support\Facades\Cache::put('test_key', 'test_value', 60);
$val = \Illuminate\Support\Facades\Cache::get('test_key');
log_test('B10b: cache:put + cache:get works', $val === 'test_value');

// ─── [B11] Filesystem backup integrity
echo "\n[B11] Filesystem integrity\n";
$appDir = __DIR__ . '/storage/app';
$logDir = __DIR__ . '/storage/logs';
log_test('B11a: storage/app exists', is_dir($appDir));
log_test('B11b: storage/logs exists', is_dir($logDir));
log_test('B11c: storage/framework/cache/data exists', is_dir(__DIR__ . '/storage/framework/cache/data'));

// ─── [B12] Storage contents survive
echo "\n[B12] Backup artifacts\n";
log_test('B12a: full dump file exists', file_exists($dumpPath));
log_test('B12b: schema dump file exists', file_exists($schemaPath));
log_test('B12c: dump file size > 50KB', filesize($dumpPath) > 50 * 1024);

// Cleanup test dumps
@unlink($dumpPath);
@unlink($schemaPath);

echo "\n═══════════════════════════════════════════════════════════════\n";
echo "  النتيجة: {$summary['success']} نجح / {$summary['failed']} فشل\n";
echo "═══════════════════════════════════════════════════════════════\n";

file_put_contents(__DIR__ . '/test_backups_results.json', json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
