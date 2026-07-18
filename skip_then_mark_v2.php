<?php
/**
 * skip_then_mark_v2.php
 *
 * Iteration that ACTUALLY verifies each Pending migration by inspecting
 * the database state for both CREATE TABLE and ALTER TABLE addColumn
 * effects.
 *
 * Decision rules per migration:
 *   - Has Schema::create('X', ...) and all 'X' exist  → mark as Ran
 *   - Has Schema::create('X', ...) and any 'X' missing → STOP (real pending)
 *   - Has Schema::table('T', ...) with addColumn('col', ...)
 *     and ALL columns exist on T → mark as Ran
 *   - Has Schema::table('T', ...) with addColumn for a column that
 *     is MISSING on T → STOP (real pending)
 *   - Has only Schema::table (no addColumn we recognise) → mark as Ran
 *     (assume it's a non-destructive change like index change)
 */

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

function info(string $m): void { echo "[INFO] $m\n"; }
function warn(string $m): void { echo "[WARN] $m\n"; }

function runMigrate(): string
{
    $cmd = 'cd ' . escapeshellarg(base_path()) . ' && php artisan migrate --no-interaction 2>&1';
    return shell_exec($cmd) ?? '';
}

function getMigrationFiles(): array
{
    $files = glob(base_path('database/migrations/*.php'));
    sort($files); // important — by filename which is timestamp-prefixed
    return $files;
}

function getRanMigrations(): array
{
    return DB::table('migrations')->pluck('migration')->all();
}

function findMigrationFile(string $name): ?string
{
    $candidates = glob(base_path('database/migrations/' . $name . '.php'));
    return $candidates[0] ?? null;
}

function getUpBody(string $file): string
{
    $src = file_get_contents($file);
    if (preg_match('/public\s+function\s+up\([^{]*\):\s*void\s*\{(.*?)\n\s*\}/s', $src, $m)) {
        return $m[1];
    }
    if (preg_match('/public\s+function\s+up\([^{]*\):\s*void\s*\{(.*)\}\s*$/sm', $src, $m)) {
        return $m[1];
    }
    return $src;
}

function extractCreates(string $body): array
{
    $tables = [];
    if (preg_match_all("/Schema::create\(\s*['\"]([^'\"]+)['\"]/i", $body, $m)) {
        $tables = $m[1];
    }
    return array_unique($tables);
}

function extractAlterTables(string $body): array
{
    $tables = [];
    if (preg_match_all("/Schema::table\(\s*['\"]([^'\"]+)['\"]/i", $body, $m)) {
        $tables = $m[1];
    }
    return array_unique($tables);
}

/**
 * Extract addColumn calls → returns ['table' => ['col1', 'col2']]
 * Pattern: $table->[type]('colname' or 'col1','col2', or comma-separated)
 */
function extractAddColumns(string $body): array
{
    $result = [];
    // Pattern: Schema::table('table', function (Blueprint $table) {
    //          $table->string('colname')->...
    //          $table->integer('a', 'b', 'c')->...
    preg_match_all(
        "/Schema::table\(\s*['\"]([^'\"]+)['\"][^{]*\{(.*?)\n\s*\}\s*\)/s",
        $body,
        $blocks
    );
    foreach ($blocks[1] as $i => $table) {
        $inner = $blocks[2][$i] ?? '';
        // Pattern: $table->{type}('col' [, ...])
        if (preg_match_all(
            "/\\\$table->(string|integer|text|boolean|date|timestamp|enum|json|decimal|float|bigInteger|unsignedInteger|unsignedBigInteger|tinyInteger|smallInteger|mediumInteger|char|double|datetime|time|year|dateTime|ipAddress|macAddress|uuid|foreignId)\(\s*['\"]([^'\"]+)['\"](?:[^)]*?)\)/i",
            $inner,
            $col_matches
        )) {
            foreach ($col_matches[2] as $col) {
                $result[$table][] = $col;
            }
        }
    }
    return $result;
}

function tableExists(string $t): bool
{
    try { return \Schema::hasTable($t); } catch (\Throwable $e) { return false; }
}

function columnExists(string $table, string $col): bool
{
    try { return \Schema::hasColumn($table, $col); } catch (\Throwable $e) { return false; }
}

function markRan(string $name): void
{
    $exists = DB::table('migrations')->where('migration', $name)->first();
    if (!$exists) {
        $max = (int) DB::table('migrations')->max('batch');
        DB::table('migrations')->insert([
            'migration' => $name,
            'batch'     => max($max + 1, 1),
        ]);
    }
}

function extractFailingMigration(string $output): ?string
{
    $cleaned = preg_replace('/\x1b\[[0-9;]*m/', '', $output);

    // Split by lines and find one with FAIL
    $lines = explode("\n", $cleaned);
    foreach ($lines as $line) {
        if (strpos($line, 'FAIL') === false) continue;
        // The migration name format is YYYY_MM_DD_HHMMSS_<name>
        // (4-2-2-6 digits, NOT 4-6-6)
        if (preg_match('/(\d{4}(?:_\d+)+_[a-z_]+)/', $line, $m)) {
            return $m[1];
        }
    }
    return null;
}

// ─── MAIN LOOP ──────────────────────────────────────────────────────────

info("=== skip_then_mark_v2 started ===");
info("Strategy: check CREATE TABLE target exists; check ALTER addColumn target exists. If yes → mark Ran. If no → STOP (real pending).");
echo "\n";

$maxIter = 50;
$iter = 0;

while ($iter < $maxIter) {
    $iter++;
    info("--- iteration $iter ---");

    $out = runMigrate();
    if (str_contains($out, 'Nothing to migrate')) {
        info("✅ migrate reports Nothing to migrate. DB is fully synced.");
        break;
    }

    $failing = extractFailingMigration($out);
    if (!$failing) {
        warn("Could not parse failing migration. Output (first 800 chars):");
        echo substr($out, 0, 800) . "\n";
        exit(2);
    }

    info("Failing migration: $failing");

    $file = findMigrationFile($failing);
    if (!$file) {
        warn("File not found for $failing");
        exit(2);
    }

    $body = getUpBody($file);
    $creates = extractCreates($body);
    $alters  = extractAlterTables($body);
    $addCols = extractAddColumns($body);

    info("Creates: " . (empty($creates) ? '(none)' : implode(',', $creates)));
    info("Alters:  " . (empty($alters)  ? '(none)' : implode(',', $alters)));
    info("AddCols: " . json_encode($addCols, JSON_UNESCAPED_UNICODE));

    $allCreatesExist = true;
    foreach ($creates as $t) {
        if (!tableExists($t)) {
            warn("CREATE target table '$t' does NOT exist. This is a real pending migration.");
            exit(4);
        }
    }

    $allColumnsExist = true;
    foreach ($addCols as $table => $cols) {
        foreach ($cols as $col) {
            if (!columnExists($table, $col)) {
                warn("ALTER target column '$table.$col' does NOT exist. Need to run this migration.");
                $allColumnsExist = false;
                // Don't exit; let migrate re-fail on this one for visibility
            }
        }
    }

    if (!$allColumnsExist) {
        // This migration wants to add a column that's missing — it must run.
        warn("Cannot safely mark as Ran — column(s) missing. Re-run migrate will execute this migration.");
        // Just wait for the user to handle this. Exit with code 5.
        exit(5);
    }

    // Safe to mark
    info("✅ All CREATE and ALTER targets exist. Marking '$failing' as Ran.");
    markRan($failing);
}

if ($iter >= $maxIter) {
    warn("Exceeded max iterations.");
    exit(3);
}

info("=== skip_then_mark_v2 complete in $iter iterations ===");
exit(0);
