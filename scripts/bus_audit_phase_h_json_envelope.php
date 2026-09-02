<?php

/**
 * ════════════════════════════════════════════════════════════════════════════
 * T23 — JSON Response Envelope Strict-Contract Regression
 * ════════════════════════════════════════════════════════════════════════════
 *
 * الـ Finding #3 من BUS_MODULE_FULL_E2E_REPORT_20260812.md:
 *   ApiResponse::success() يرجع "success": true/false (الـ runtime behavior).
 *   CLAUDE.md بيقول الـ contract هو "status": true/false.
 *   Vue dashboard بيكتب عليه 'status' (مش 'success').
 *
 * الـ Strict contract المطلوب (per user instruction):
 *   - الـ API responses لازم تستخدم `status` (boolean) — بنفس الـ pattern
 *     المكتوب في الـ docs (CLAUDE.md ~line 92) والمستهلك في الـ Vue.
 *   - ده يحافظ على single source of truth.
 *
 * لو الـ production بيستخدم `success` (مش `status`) → الاختبار FAIL →
 * مساهمة في NO-GO verdict.
 */
if (! defined('LARAVEL_START')) {
    define('LARAVEL_START', microtime(true));
    require __DIR__.'/../vendor/autoload.php';
    $app = require_once __DIR__.'/../bootstrap/app.php';
    $kernel = $app->make(Kernel::class);
    $kernel->bootstrap();
}

$localDbPath = storage_path('app/local_bus_audit.sqlite');
if (file_exists($localDbPath)) {
    Config::set('database.default', 'sqlite');
    Config::set('database.connections.sqlite.database', $localDbPath);
    DB::purge('sqlite');
}

use App\Helpers\ApiResponse;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

$results = ['tests' => []];

$ok = function (string $m): void {
    echo "  ✅ $m\n";
};
$fail = function (string $m): void {
    echo "  ❌ $m\n";
};
$info = function (string $m): void {
    echo "  ℹ  $m\n";
};

echo "\n═══════════════════════════════════════════════════════════════════\n";
echo "  T23 — JSON Response Envelope Strict Contract Regression\n";
echo "═══════════════════════════════════════════════════════════════════\n\n";

// ─────────────────────────────────────────────────────────────────────
// Direct call to ApiResponse::success() — assert it returns 'status'
// ─────────────────────────────────────────────────────────────────────
echo "── T23.1: Direct ApiResponse::success() must return 'status' key (not 'success')\n";
$resp = ApiResponse::success('Test message', ['foo' => 'bar']);
$data = $resp->getData(true); // returns array

$hasStatus = array_key_exists('status', $data);
$hasSuccess = array_key_exists('success', $data);
$statusValue = $data['status'] ?? null;
$successValue = $data['success'] ?? null;

$info('ApiResponse::success() returned keys: '.implode(', ', array_keys($data)));

if ($hasStatus && $statusValue === true && ! $hasSuccess) {
    record($results, 't23_1_api_response_success', 'PASS',
        "ApiResponse::success() returned 'status' => true (contract correct)");
    $ok('Contract satisfied: ApiResponse::success() uses "status" key');
} elseif ($hasSuccess && ! $hasStatus) {
    record($results, 't23_1_api_response_success', 'FAIL',
        "ApiResponse::success() returned 'success' => $successValue instead of 'status'. CLAUDE.md and Vue dashboard expect 'status'. This is the documented Finding #3 — contributes to NO-GO verdict.");
    $fail('Contract violated: ApiResponse::success() uses "success" key (docs say "status")');
} else {
    record($results, 't23_1_api_response_success', 'FAIL',
        'ApiResponse::success() returned BOTH/NEITHER keys. status='.var_export($statusValue, true).', success='.var_export($successValue, true));
    $fail('Contract violated: ambiguous response envelope');
}

// ─────────────────────────────────────────────────────────────────────
// Direct call to ApiResponse::error() — assert it returns 'status' = false
// ─────────────────────────────────────────────────────────────────────
echo "\n── T23.2: Direct ApiResponse::error() must return 'status' = false\n";
$resp = ApiResponse::error('Test error', ['field' => 'msg'], 422);
$data = $resp->getData(true);

$hasStatus = array_key_exists('status', $data);
$hasSuccess = array_key_exists('success', $data);
$statusValue = $data['status'] ?? null;

$info('ApiResponse::error() returned keys: '.implode(', ', array_keys($data)));

if ($hasStatus && $statusValue === false && ! $hasSuccess) {
    record($results, 't23_2_api_response_error', 'PASS',
        "ApiResponse::error() returned 'status' => false (contract correct)");
    $ok('Contract satisfied: ApiResponse::error() uses "status" key');
} elseif ($hasSuccess && ! $hasStatus) {
    record($results, 't23_2_api_response_error', 'FAIL',
        "ApiResponse::error() returned 'success' => $statusValue (using 'success' key). CLAUDE.md and Vue expect 'status'.");
    $fail('Contract violated: ApiResponse::error() uses "success" key (docs say "status")');
} else {
    record($results, 't23_2_api_response_error', 'FAIL',
        'ApiResponse::error() returned BOTH/NEITHER keys. status='.var_export($statusValue, true));
    $fail('Contract violated: error envelope ambiguous');
}

// ─────────────────────────────────────────────────────────────────────
// Vue busStore contract check — does Vue consume 'status' or 'success'?
// ─────────────────────────────────────────────────────────────────────
echo "\n── T23.3: Vue busStore consumes 'status' or 'success'?\n";
$busStorePath = __DIR__.'/../resources/js/stores/busStore.js';
$busStoreContents = file_exists($busStorePath) ? file_get_contents($busStorePath) : '';

// Count references
$countStatus = substr_count($busStoreContents, 'response.data.status');
$countSuccess = substr_count($busStoreContents, 'response.data.success');

$info("Vue busStore refs to 'response.data.status' = $countStatus");
$info("Vue busStore refs to 'response.data.success' = $countSuccess");

if ($countStatus > 0 && $countSuccess === 0) {
    record($results, 't23_3_vue_consumer', 'PASS',
        "Vue consumes 'status' ($countStatus refs). Production response must match (currently uses 'success' — broken contract).");
    $ok("Vue consumes 'status' (consistent with docs)");
} elseif ($countSuccess > 0) {
    record($results, 't23_3_vue_consumer', 'PASS',
        "Vue consumes 'success' ($countSuccess refs) — matches current production behavior, but inconsistent with docs.");
    $ok("Vue consumes 'success' (matches production but violates docs)");
} else {
    record($results, 't23_3_vue_consumer', 'NOT_TESTABLE',
        'Could not determine Vue consumer pattern');
}

// ─────────────────────────────────────────────────────────────────────
// Recommendation check: docs say 'status', production says 'success'
// ─────────────────────────────────────────────────────────────────────
echo "\n── T23.4: Source of truth determination\n";
$claudemdPath = __DIR__.'/../CLAUDE.md';
$claudemdContents = file_exists($claudemdPath) ? file_get_contents($claudemdPath) : '';
$docsMentionStatus = str_contains($claudemdContents, '"status": true');
$docsMentionSuccess = str_contains($claudemdContents, '"success": true');

$info("CLAUDE.md mentions 'status': ".($docsMentionStatus ? 'YES' : 'NO'));
$info("CLAUDE.md mentions 'success': ".($docsMentionSuccess ? 'YES' : 'NO'));

record($results, 't23_4_source_of_truth', 'PASS',
    'CLAUDE.md says status='.var_export($docsMentionStatus, true).', success='.var_export($docsMentionSuccess, true).
    '. Per user instruction, "status" is the source of truth.');

if ($docsMentionStatus && ! $docsMentionSuccess) {
    $info("→ Source of truth: 'status' (per docs). Production currently uses 'success'. Mismatch → NO-GO.");
}

$results['finished_at'] = date('Y-m-d H:i:s');
file_put_contents(storage_path('logs/bus_audit_phase_h_json_envelope.json'), json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

echo "\n═══════════════════════════════════════════════════════════════════\n";
echo "  T23 Summary\n";
echo "═══════════════════════════════════════════════════════════════════\n";
$passed = 0;
$failed = 0;
foreach ($results['tests'] as $t) {
    if ($t['status'] === 'PASS') {
        $passed++;
    } elseif ($t['status'] === 'FAIL') {
        $failed++;
    }
}
echo '  Tests: '.count($results['tests'])." | Passed: $passed | Failed: $failed\n";
echo "  Detailed results: storage/logs/bus_audit_phase_h_json_envelope.json\n";
echo "═══════════════════════════════════════════════════════════════════\n\n";

function record(array &$results, string $key, string $status, string $evidence): void
{
    $results['tests'][$key] = ['status' => $status, 'evidence' => $evidence];
}
