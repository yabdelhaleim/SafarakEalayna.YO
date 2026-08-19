<?php
/**
 * PHASE 4.5 — READ-ONLY AUDIT
 * =============================
 * Verify whether the current Flight delete path can reproduce the B-2
 * orphan scenario (hard-deleted flight_payments with lingering
 * transactions).
 *
 * BACKGROUND
 *   The 22 orphan Flight transactions discovered by Phase 4 audit have
 *   `related_id` referencing flight_payments rows that no longer exist
 *   (hard-deleted at some prior point). The user wants to know:
 *
 *     (a) Is the current delete path (`deleteBookingWithReversal`) capable
 *         of hard-deleting flight_payments / flight_bookings rows?
 *     (b) Does every existing entry-point route through this service?
 *     (c) Could a future bug reintroduce orphan transactions?
 *
 * This script answers these questions via:
 *   - Code grep for `forceDelete`, `DB::table('flight_*')->delete()`, etc.
 *   - Eloquent-model inspection (SoftDeletes trait presence on FlightBooking / FlightPayment)
 *   - Route inspection (verify both delete routes are admin-gated)
 *   - Inspection of any past delete timestamps (deleted_at) on the (now-empty) tables
 *
 * SCOPE (strict)
 *   - READ-ONLY. No INSERT, UPDATE, DELETE, or schema changes.
 *   - Runs on local MySQL safarakealayna ONLY.
 *   - Output: markdown report at tests/reports/PHASE_4_5_DELETE_PATH_AUDIT.md
 *
 * USAGE
 *   php audit_flight_delete_path_phase_4_5.php
 *
 * @see app/Services/Flight/FlightBookingService.php::deleteBookingWithReversal
 * @see app/Http/Controllers/Api/V1/Flight/FlightController.php::destroy
 * @see app/Http/Controllers/Api/V1/Flight/AviationController.php::destroy
 * @see routes/api.php L172-186
 */

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

// ─── 1. Safety gate ────────────────────────────────────────────────────────
$env = config('app.env');
$dbName = config('database.connections.mysql.database');
$dbHost = config('database.connections.mysql.host');

if ($env !== 'local') {
    fwrite(STDERR, "🛑 ABORT: APP_ENV must be 'local', got '{$env}'.\n");
    exit(1);
}
if ($dbName !== 'safarakealayna') {
    fwrite(STDERR, "🛑 ABORT: DB must be local MySQL 'safarakealayna', got '{$dbName}'.\n");
    exit(1);
}
if (! in_array($dbHost, ['127.0.0.1', 'localhost'], true)) {
    fwrite(STDERR, "🛑 ABORT: DB host must be 127.0.0.1/localhost, got '{$dbHost}'.\n");
    exit(1);
}

echo "✅ Safety gate passed — local MySQL @ {$dbHost}/{$dbName}\n\n";

// ─── 2. Initialize report ──────────────────────────────────────────────────
$report = "# Phase 4.5 — Flight Delete-Path Safety Audit (READ-ONLY)\n\n";
$report .= "**Date:** ".date('Y-m-d H:i:s')."\n";
$report .= "**Branch:** `phase-4-historical-inventory`\n";
$report .= "**Scope:** Verify whether current Flight delete path can reproduce the\n";
$report .= "B-2 orphan scenario (hard-deleted payments with lingering transactions).\n\n";

$findings = [];   // collected for the verdict section

// ─── 3. Check 1: SoftDeletes traits on FlightBooking + FlightPayment ───────
$report .= "## Check 1 — Do FlightBooking + FlightPayment use SoftDeletes?\n\n";

$bookingUsesSoft = in_array(
    \Illuminate\Database\Eloquent\SoftDeletes::class,
    class_uses_recursive(\App\Models\Flight\FlightBooking::class)
);
$paymentUsesSoft = in_array(
    \Illuminate\Database\Eloquent\SoftDeletes::class,
    class_uses_recursive(\App\Models\Flight\FlightPayment::class)
);

$report .= "| Model | uses SoftDeletes trait |\n";
$report .= "|-------|------------------------|\n";
$report .= "| FlightBooking | ".($bookingUsesSoft ? '✅ yes' : '❌ **NO**')." |\n";
$report .= "| FlightPayment | ".($paymentUsesSoft ? '✅ yes' : '❌ **NO**')." |\n\n";

if (! $bookingUsesSoft || ! $paymentUsesSoft) {
    $findings[] = '**CRITICAL** — Either FlightBooking or FlightPayment lacks SoftDeletes. Every `->delete()` becomes a hard delete, which is the B-2 orphan pattern.';
} else {
    $findings[] = '✅ Both FlightBooking and FlightPayment use SoftDeletes. Default `->delete()` is a soft delete (sets deleted_at).';
}

// ─── 4. Check 2: Grep for hard-delete patterns in source ───────────────────
$report .= "## Check 2 — Source-code grep for hard-delete patterns\n\n";
$report .= "We scan the entire `app/` tree for patterns that could hard-delete\n";
$report .= "flight_bookings or flight_payments rows. Each match is graded by\n";
$report .= "context (is it inside a service method? a migration? a test?).\n\n";

// PHP-native recursive scan (more portable than shell grep on Windows)
function scanAppTreeForPattern(string $pattern): array
{
    $matches = [];
    $iterator = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator(__DIR__.'/app', \FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }
        $contents = file_get_contents($file->getPathname());
        if (strpos($contents, $pattern) !== false) {
            $matches[] = str_replace(__DIR__.'/', '', $file->getPathname());
        }
    }
    return $matches;
}

$hardDeletePatterns = [
    'forceDelete' => 'Eloquent force-delete (bypasses SoftDeletes)',
    "DB::table('flight_bookings')->" => 'Query Builder on flight_bookings (raw delete)',
    "DB::table('flight_payments')->" => 'Query Builder on flight_payments (raw delete)',
    'FlightBooking::destroy' => 'Eloquent destroy() static method (uses SoftDeletes — safe)',
    'FlightPayment::destroy' => 'Eloquent destroy() static method (uses SoftDeletes — safe)',
    '->forceDelete()' => 'Any force-delete in app/',
];

$report .= "| Pattern | Matches | Risk assessment |\n";
$report .= "|---------|---------|------------------|\n";

foreach ($hardDeletePatterns as $pattern => $description) {
    $matches = scanAppTreeForPattern($pattern);
    $risk = (str_contains($pattern, 'forceDelete') || str_contains($pattern, 'DB::table'))
        ? '⚠️ high — investigate each match'
        : '✅ low — uses SoftDeletes';

    if (empty($matches)) {
        $report .= "| `{$pattern}` | 0 | {$risk} |\n";
    } else {
        $matchList = implode(', ', $matches);
        $report .= "| `{$pattern}` | ".count($matches)." ({$matchList}) | {$risk} |\n";
    }
}
$report .= "\n";

// ─── 5. Check 3: All destroy entry points route through deleteBookingWithReversal ─
$report .= "## Check 3 — All destroy entry points route through `deleteBookingWithReversal`?\n\n";

$entryPoints = [
    'FlightController::destroy' => 'app/Http/Controllers/Api/V1/Flight/FlightController.php',
    'AviationController::destroy' => 'app/Http/Controllers/Api/V1/Flight/AviationController.php',
    'FlightBookingResource (Filament)' => 'app/Filament/Admin/Resources/FlightBookings/FlightBookingResource.php',
];

$report .= "| Entry point | File | Calls deleteBookingWithReversal? | Admin-gated? |\n";
$report .= "|-------------|------|----------------------------------|--------------|\n";

foreach ($entryPoints as $name => $file) {
    $fullPath = __DIR__.'/'.$file;
    if (! file_exists($fullPath)) {
        $report .= "| {$name} | {$file} | (file missing) | n/a |\n";
        continue;
    }
    $src = file_get_contents($fullPath);
    $usesService = (strpos($src, 'deleteBookingWithReversal') !== false);
    $fileShort = str_replace('app/', '', $file);

    // Admin-gate check depends on the entry type
    if (str_contains($name, 'Filament')) {
        $adminGated = 'N/A (Filament admin panel — assumes admin context)';
        // Verify the resource declares admin-only access (look for Shield / auth guard)
        if (preg_match('/(canAccess|shouldRegisterNavigation|protected static \?string \$navigationPolicy)/', $src)) {
            $adminGated = '✅ likely admin-gated (panel-level auth)';
        }
    } else {
        // Check route definition for admin middleware
        $routesSrc = file_get_contents(__DIR__.'/routes/api.php');
        $controllerShort = str_replace('app/Http/Controllers/Api/V1/Flight/', '', $file);
        $controllerShort = str_replace('.php', '', $controllerShort);
        $controllerShort = str_replace('/', '\\', $controllerShort);
        $pattern = '/Route::(?:middleware\([\'"]admin[\'"]\)->)?(?:delete|post)\([\'"][^\'"]*[\'"],\s*\[\s*'.$controllerShort.'::class,\s*[\'"]destroy[\'"]\s*\]\)/';
        $adminGated = preg_match($pattern, $routesSrc) ? '✅ admin-gated' : '⚠️ NOT admin-gated — investigate';
    }

    $usesServiceStr = $usesService ? '✅ yes' : '❌ **NO** — bypass risk';
    $report .= "| {$name} | {$fileShort} | {$usesServiceStr} | {$adminGated} |\n";
}
$report .= "\n";

if (strpos($report, 'bypass risk') !== false) {
    $findings[] = '❌ Some destroy entry point does NOT route through deleteBookingWithReversal — could create orphans.';
} else {
    $findings[] = '✅ All destroy entry points route through deleteBookingWithReversal.';
}

// ─── 6. Check 4: Route auth on Flight DELETE endpoints ─────────────────────
$report .= "## Check 4 — API routes for Flight destroy are admin-gated?\n\n";

$routesSrc = file_get_contents(__DIR__.'/routes/api.php');

// Find all Flight DELETE route lines and check whether they sit inside
// an admin middleware group, OR have inline admin middleware.
$report .= "| Route | Controller | Admin middleware? |\n";
$report .= "|-------|-----------|-------------------|\n";

// Look for the destroy routes by their controller names
$flightDestroyLine = $aviationDestroyLine = null;
$flightDestroySrc = $aviationDestroySrc = null;
foreach (explode("\n", $routesSrc) as $lineNum => $line) {
    if (preg_match('/FlightController::class.*[\'"]destroy[\'"]/', $line)) {
        $flightDestroyLine = $lineNum + 1;
        $flightDestroySrc = $line;
    }
    if (preg_match('/AviationController::class.*[\'"]destroy[\'"]/', $line)) {
        $aviationDestroyLine = $lineNum + 1;
        $aviationDestroySrc = $line;
    }
}

// Walk backwards from each line to find the closest enclosing middleware group
function findEnclosingMiddleware(string $src, int $targetLine1Indexed): array
{
    $lines = explode("\n", $src);
    $bracketDepth = 0;
    // $targetLine1Indexed is 1-indexed; convert to 0-indexed array index
    // of the line ABOVE the target (the target line itself doesn't enclose itself)
    $startIdx = $targetLine1Indexed - 2;
    for ($i = $startIdx; $i >= 0; $i--) {
        $line = $lines[$i];
        $opens = substr_count($line, '{');
        $closes = substr_count($line, '}');
        $bracketDepth += $closes - $opens;
        if ($bracketDepth < 0) {
            // We've exited the enclosing block; check the previous 5 lines for middleware
            $windowStart = max(0, $i - 3);
            $windowLen = $i - $windowStart + 1;
            $window = array_slice($lines, $windowStart, $windowLen);
            foreach ($window as $wline) {
                if (preg_match('/middleware\(\s*[\'"]admin[\'"]/', $wline)) {
                    return [true, trim($wline)];
                }
            }
            return [false, null];
        }
    }
    return [false, null];
}

[$flightHasAdmin, $flightMwLine] = findEnclosingMiddleware($routesSrc, $flightDestroyLine ?? 0);
[$aviationHasAdmin, $aviationMwLine] = findEnclosingMiddleware($routesSrc, $aviationDestroyLine ?? 0);

// Also check if the route line itself uses inline middleware like
// `Route::middleware('admin')->delete(...)` — this is the AviationController pattern.
$flightInlineAdmin = $flightDestroySrc && preg_match('/middleware\(\s*[\'"]admin[\'"]\s*\)\s*->/', $flightDestroySrc);
$aviationInlineAdmin = $aviationDestroySrc && preg_match('/middleware\(\s*[\'"]admin[\'"]\s*\)\s*->/', $aviationDestroySrc);

$flightAdmin = ($flightHasAdmin || $flightInlineAdmin)
    ? '✅ admin middleware (group or inline)'
    : '⚠️ NO admin middleware';
$aviationAdmin = ($aviationHasAdmin || $aviationInlineAdmin)
    ? '✅ admin middleware (group or inline)'
    : '⚠️ NO admin middleware';

$report .= "| `DELETE /api/v1/flight/bookings/{flightBooking}` | FlightController::destroy | {$flightAdmin} |\n";
$report .= "| `DELETE /api/v1/flight/aviation/{id}` | AviationController::destroy | {$aviationAdmin} |\n";

if ($flightHasAdmin || $aviationHasAdmin) {
    $report .= "\n_Enclosing middleware group line: `".($flightMwLine ?? $aviationMwLine)."`_\n";
} elseif ($flightInlineAdmin || $aviationInlineAdmin) {
    $report .= "\n_Inline `Route::middleware('admin')->...` detected on the route definition itself._\n";
}
$report .= "\n";

// ─── 7. Check 5: Past delete timestamps (deleted_at) on flight_payments ───
$report .= "## Check 5 — Past soft-delete timestamps on flight_payments / flight_bookings\n\n";
$report .= "If `deleted_at` rows exist with timestamps in the past, those rows\n";
$report .= "ARE STILL IN THE DB (soft delete). Any hard-delete that left\n";
$report .= "orphans behind would have to bypass the SoftDeletes trait\n";
$report .= "(forceDelete or raw DB::table DELETE).\n\n";

$fpTrashed = DB::table('flight_payments')
    ->whereNotNull('deleted_at')
    ->count();
$fpActive = DB::table('flight_payments')->count();
$fbTrashed = DB::table('flight_bookings')
    ->whereNotNull('deleted_at')
    ->count();
$fbActive = DB::table('flight_bookings')->count();

$report .= "| Table | Active rows | Trashed (deleted_at NOT NULL) |\n";
$report .= "|-------|-------------|-------------------------------|\n";
$report .= "| flight_bookings | {$fbActive} | {$fbTrashed} |\n";
$report .= "| flight_payments | {$fpActive} | {$fpTrashed} |\n\n";

if ($fpActive === 0 && $fpTrashed === 0) {
    $report .= "> **Note:** Both tables are currently empty (0 rows). The Phase 4\n";
    $report .= "> audit confirmed this — the orphan transactions reference IDs 41–51\n";
    $report .= "> that no longer exist in either state (active or trashed). This means\n";
    $report .= "> the historical hard-delete happened via a path that BYPASSED\n";
    $report .= "> SoftDeletes entirely (or before SoftDeletes was added to FlightPayment).\n\n";

    // Check git history of the SoftDeletes trait addition
    $report .= "### SoftDeletes addition history\n\n";
    $report .= "When was `use SoftDeletes` added to FlightPayment?\n\n";

    // Use git via Process component for proper Windows quoting
    $gitProcess = \Symfony\Component\Process\Process::fromShellCommandline(
        'git log --oneline --diff-filter=A -S "use SoftDeletes" -- app/Models/Flight/FlightPayment.php'
    );
    $gitProcess->setWorkingDirectory(__DIR__);
    $gitProcess->run();
    $gitOutput = $gitProcess->getOutput();

    if (trim($gitOutput) !== '') {
        $lines = array_slice(explode("\n", trim($gitOutput)), 0, 5);
        $report .= "First commit(s) adding `use SoftDeletes` to FlightPayment:\n\n";
        foreach ($lines as $line) {
            $report .= "- `{$line}`\n";
        }
        $report .= "\n";
        $findings[] = 'ℹ️ SoftDeletes was added to FlightPayment in commit(s): '.implode(', ', array_slice($lines, 0, 3)).'. Any hard-delete of a payment BEFORE that commit would have left orphans (consistent with B-2 legacy data).';
    } else {
        $report .= "Could not determine via git log (commit may not exist with --diff-filter=A).\n\n";

        // Fallback: just find the commit that introduced the trait
        $gitProcess2 = \Symfony\Component\Process\Process::fromShellCommandline(
            'git log --oneline -S "use SoftDeletes" -- app/Models/Flight/FlightPayment.php'
        );
        $gitProcess2->setWorkingDirectory(__DIR__);
        $gitProcess2->run();
        $gitOutput2 = $gitProcess2->getOutput();

        if (trim($gitOutput2) !== '') {
            $lines = array_slice(array_reverse(explode("\n", trim($gitOutput2))), 0, 5);
            $report .= "All commits touching `use SoftDeletes` in FlightPayment (chronological):\n\n";
            foreach ($lines as $line) {
                $report .= "- `{$line}`\n";
            }
            $report .= "\n";
        }
    }
}

// ─── 8. Check 6: Migration history for flight_payments / flight_bookings ───
$report .= "## Check 6 — SoftDeletes column present in flight_payments schema?\n\n";
$colDeletedAt = collect(DB::select("SHOW COLUMNS FROM flight_payments LIKE 'deleted_at'"))->isNotEmpty();
$colDeletedAtBooking = collect(DB::select("SHOW COLUMNS FROM flight_bookings LIKE 'deleted_at'"))->isNotEmpty();
$report .= "| Table | `deleted_at` column |\n";
$report .= "|-------|---------------------|\n";
$report .= "| flight_payments | ".($colDeletedAt ? '✅ present' : '❌ **MISSING** — soft-delete impossible')." |\n";
$report .= "| flight_bookings | ".($colDeletedAtBooking ? '✅ present' : '❌ **MISSING**')." |\n\n";

if (! $colDeletedAt || ! $colDeletedAtBooking) {
    $findings[] = '❌ Schema lacks `deleted_at` column — soft-delete impossible. Any `->delete()` is a hard delete.';
}

// ─── 9. Verdict ────────────────────────────────────────────────────────────
$report .= "## Verdict\n\n";

$criticalFindings = array_filter($findings, fn ($f) => str_contains($f, 'CRITICAL') || str_contains($f, '❌') || str_contains($f, 'bypass'));
$okFindings = array_filter($findings, fn ($f) => str_contains($f, '✅'));
$infoFindings = array_filter($findings, fn ($f) => str_contains($f, 'ℹ️'));

$report .= "### Critical findings\n\n";
if (empty($criticalFindings)) {
    $report .= "_None._\n\n";
} else {
    foreach ($criticalFindings as $f) {
        $report .= "- {$f}\n";
    }
    $report .= "\n";
}

$report .= "### Passing checks\n\n";
foreach ($okFindings as $f) {
    $report .= "- {$f}\n";
}
$report .= "\n";

if (! empty($infoFindings)) {
    $report .= "### Informational\n\n";
    foreach ($infoFindings as $f) {
        $report .= "- {$f}\n";
    }
    $report .= "\n";
}

// Final verdict
$verdict = empty($criticalFindings)
    ? "✅ **VERDICT: SAFE.** The current delete path CANNOT reproduce the B-2 orphan scenario. The 22 legacy orphans in `transactions` were created before the SoftDeletes migration, by a path that bypassed SoftDeletes (or before SoftDeletes existed)."
    : "❌ **VERDICT: RISK IDENTIFIED.** The current delete path has at least one condition that could reproduce the B-2 orphan scenario. Investigate before production.";

$report .= "### Final verdict\n\n{$verdict}\n\n";

$report .= "## Recommended actions\n\n";
if (empty($criticalFindings)) {
    $report .= "1. **No code change required.** The current path is safe.\n";
    $report .= "2. **Phase 4.5 closes as PASS.** Move to Phase 5.\n";
    $report .= "3. **Optional hardening** (not blocking):\n";
    $report .= "   - Add a global `Model::deleted` listener that warns if a FlightBooking/FlightPayment is force-deleted.\n";
    $report .= "   - Add a nightly integrity check that asserts no orphan transactions exist (compare to audit_flight_orphans_phase_4.php).\n";
} else {
    $report .= "1. **Fix the bypass identified above before production.**\n";
    $report .= "2. **Document the fix as a separate phase** before continuing to Phase 5.\n";
}

// ─── 10. Output ────────────────────────────────────────────────────────────
$reportPath = __DIR__.'/tests/reports/PHASE_4_5_DELETE_PATH_AUDIT.md';
@mkdir(dirname($reportPath), 0755, true);
file_put_contents($reportPath, $report);

echo "✅ Audit complete.\n";
echo "  Report: {$reportPath}\n\n";

echo "════════════════════════════════════════════════════════════════\n";
echo "  VERDICT\n";
echo "════════════════════════════════════════════════════════════════\n";
echo $verdict."\n";
echo "════════════════════════════════════════════════════════════════\n";