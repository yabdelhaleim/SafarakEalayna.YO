<?php

/**
 * ════════════════════════════════════════════════════════════════════════
 * T23 — JSON Response Envelope Comprehensive Regression
 * ════════════════════════════════════════════════════════════════════════
 *
 * Validates the canonical envelope contract:
 *   { status: true|false, message, data, errors }
 *
 * NOT { success: true|false, ... } (the legacy drift).
 *
 * Per CLAUDE.md line 89 and the T23 strict-contract test.
 */
if (! defined('LARAVEL_START')) {
    define('LARAVEL_START', microtime(true));
    require __DIR__.'/../vendor/autoload.php';
    $app = require_once __DIR__.'/../bootstrap/app.php';
    $kernel = $app->make(Kernel::class);
    $kernel->bootstrap();
}

use App\Helpers\ApiResponse;
use App\Http\Middleware\StandardizeApiResponse;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

$results = ['tests' => []];

$ok = function (string $m) use (&$results): void {
    echo "  ✅ $m\n";
};
$fail = function (string $m) use (&$results): void {
    echo "  ❌ $m\n";
};
$info = function (string $m): void {
    echo "  ℹ  $m\n";
};

function record(array &$results, string $key, string $status, string $evidence): void
{
    $results['tests'][$key] = ['status' => $status, 'evidence' => $evidence];
}

echo "═══════════════════════════════════════════════════════════════════\n";
echo "  T23 — JSON Envelope Comprehensive Regression\n";
echo "═══════════════════════════════════════════════════════════════════\n\n";

// ─────────────────────────────────────────────────────────────────────
// T1: ApiResponse::success() emits 'status' not 'success'
// ─────────────────────────────────────────────────────────────────────
echo "── T1: ApiResponse::success() emits 'status' not 'success'\n";
$resp = ApiResponse::success('msg', ['foo' => 'bar']);
$data = $resp->getData(true);
$hasStatus = array_key_exists('status', $data);
$hasSuccess = array_key_exists('success', $data);
$statusOk = $data['status'] === true;
if ($hasStatus && $statusOk && ! $hasSuccess) {
    record($results, 't1_success_status', 'PASS', "ApiResponse::success() uses status=true (no 'success' key)");
    $ok('success() emits status=true (no success key)');
} else {
    record($results, 't1_success_status', 'FAIL',
        'Keys: '.implode(',', array_keys($data)).'; status='.var_export($data['status'] ?? null, true).', success='.var_export($data['success'] ?? null, true));
    $fail('success() does not emit status=true');
}

// ─────────────────────────────────────────────────────────────────────
// T2: ApiResponse::error() emits 'status' = false
// ─────────────────────────────────────────────────────────────────────
echo "\n── T2: ApiResponse::error() emits status=false\n";
$resp = ApiResponse::error('msg', ['field' => 'err'], 422);
$data = $resp->getData(true);
$hasStatus = array_key_exists('status', $data);
$hasSuccess = array_key_exists('success', $data);
$statusOk = $data['status'] === false;
if ($hasStatus && $statusOk && ! $hasSuccess) {
    record($results, 't2_error_status', 'PASS', "ApiResponse::error() uses status=false (no 'success' key)");
    $ok('error() emits status=false (no success key)');
} else {
    record($results, 't2_error_status', 'FAIL', 'Keys: '.implode(',', array_keys($data)));
    $fail('error() does not emit status=false');
}

// ─────────────────────────────────────────────────────────────────────
// T3: ApiResponse::paginated() emits 'status' = true
// ─────────────────────────────────────────────────────────────────────
echo "\n── T3: ApiResponse::paginated() emits status=true\n";
$items = [['id' => 1], ['id' => 2], ['id' => 3]];
$paginator = new LengthAwarePaginator($items, 3, 15, 1);
$resp = ApiResponse::paginated('msg', $items, $paginator);
$data = $resp->getData(true);
$hasStatus = array_key_exists('status', $data);
$hasSuccess = array_key_exists('success', $data);
$hasItems = isset($data['data']['items']);
$hasPagination = isset($data['data']['pagination']);
if ($hasStatus && $data['status'] === true && ! $hasSuccess && $hasItems && $hasPagination) {
    record($results, 't3_paginated_status', 'PASS',
        'ApiResponse::paginated() uses status=true, data.items + data.pagination present');
    $ok('paginated() emits status=true with items + pagination');
} else {
    record($results, 't3_paginated_status', 'FAIL',
        'Keys: '.implode(',', array_keys($data)).'; data keys: '.implode(',', array_keys($data['data'] ?? [])));
    $fail('paginated() does not emit status=true');
}

// ─────────────────────────────────────────────────────────────────────
// T4: StandardizeApiResponse middleware preserves 'status' (does NOT rewrite to 'success')
// ─────────────────────────────────────────────────────────────────────
echo "\n── T4: StandardizeApiResponse middleware preserves 'status' (no rewrite to 'success')\n";
$middleware = new StandardizeApiResponse;
$request = Request::create('/api/v1/bus/dashboard', 'GET');
$inputResponse = response()->json(['status' => true, 'message' => 'm', 'data' => ['x' => 1], 'errors' => null]);
$outputResponse = $middleware->handle($request, fn () => $inputResponse);
$outData = $outputResponse->getData(true);
if (isset($outData['status']) && $outData['status'] === true && ! isset($outData['success'])) {
    record($results, 't4_middleware_preserves_status', 'PASS',
        "Middleware preserved 'status' key on output (no rewrite to 'success')");
    $ok('Middleware preserves status (no rewrite to success)');
} else {
    record($results, 't4_middleware_preserves_status', 'FAIL',
        'Output keys: '.implode(',', array_keys($outData)).'; status='.var_export($outData['status'] ?? null, true));
    $fail('Middleware rewrote/removal of status');
}

// ─────────────────────────────────────────────────────────────────────
// T5: Backward compat — input response with 'success' key → middleware emits 'status'
// ─────────────────────────────────────────────────────────────────────
echo "\n── T5: Backward compat — legacy 'success' input → output uses 'status'\n";
$inputResponse = response()->json(['success' => true, 'message' => 'm', 'data' => ['y' => 2], 'errors' => null]);
$outputResponse = $middleware->handle($request, fn () => $inputResponse);
$outData = $outputResponse->getData(true);
if (isset($outData['status']) && $outData['status'] === true && ! isset($outData['success'])) {
    record($results, 't5_backward_compat', 'PASS',
        "Legacy 'success' input migrated to 'status' on output (no regression for 3rd-party callers)");
    $ok('Backward compat: success input → status output');
} else {
    record($results, 't5_backward_compat', 'FAIL',
        'Output keys: '.implode(',', array_keys($outData)));
    $fail('Backward compat broken');
}

// ─────────────────────────────────────────────────────────────────────
// T6: Error response carries correct status code
// ─────────────────────────────────────────────────────────────────────
echo "\n── T6: Error response status code = 422\n";
$resp = ApiResponse::error('Validation failed', ['name' => 'required'], 422);
if ($resp->getStatusCode() === 422) {
    record($results, 't6_error_status_code', 'PASS', 'HTTP status code = 422');
    $ok('Error response status code = 422');
} else {
    record($results, 't6_error_status_code', 'FAIL', 'Got status code: '.$resp->getStatusCode());
    $fail('Wrong status code');
}

// ─────────────────────────────────────────────────────────────────────
// T7: Success response carries correct status code
// ─────────────────────────────────────────────────────────────────────
echo "\n── T7: Success response status code = 200\n";
$resp = ApiResponse::success('OK', ['x' => 1]);
if ($resp->getStatusCode() === 200) {
    record($results, 't7_success_status_code', 'PASS', 'HTTP status code = 200');
    $ok('Success response status code = 200');
} else {
    record($results, 't7_success_status_code', 'FAIL', 'Got status code: '.$resp->getStatusCode());
    $fail('Wrong status code');
}

// ─────────────────────────────────────────────────────────────────────
// T8: Livewire pass-through still works (regression for Filament)
// ─────────────────────────────────────────────────────────────────────
echo "\n── T8: StandardizeApiResponse passes through Livewire headers\n";
$lwRequest = Request::create('/api/v1/bus/dashboard', 'GET');
$lwRequest->headers->set('X-Livewire', '1');
$inputResponse = response()->json(['status' => true, 'data' => ['x' => 1]]);
$outputResponse = $middleware->handle($lwRequest, fn () => $inputResponse);
$outData = $outputResponse->getData(true);
if (isset($outData['status']) && $outData['status'] === true && ! isset($outData['message'])) {
    record($results, 't8_livewire_passthrough', 'PASS', 'Livewire header preserved the original response');
    $ok('Livewire pass-through preserved');
} else {
    record($results, 't8_livewire_passthrough', 'FAIL', 'Output: '.json_encode($outData));
    $fail('Livewire pass-through violated');
}

// ─────────────────────────────────────────────────────────────────────
// T9: Full end-to-end via middleware with paginated response
// ─────────────────────────────────────────────────────────────────────
echo "\n── T9: Full middleware pipeline with paginated response\n";
$items = [['id' => 1], ['id' => 2]];
$paginator = new LengthAwarePaginator($items, 2, 15, 1);
$inputResponse = ApiResponse::paginated('List', $items, $paginator);
$outputResponse = $middleware->handle($request, fn () => $inputResponse);
$outData = $outputResponse->getData(true);
if (isset($outData['status']) && $outData['status'] === true && ! isset($outData['success']) && isset($outData['data']['items'])) {
    record($results, 't9_paginated_through_middleware', 'PASS',
        'Paginated response survived middleware unchanged (status=true, items present)');
    $ok('Paginated response through middleware OK');
} else {
    record($results, 't9_paginated_through_middleware', 'FAIL', 'Output: '.json_encode($outData));
    $fail('Paginated middleware pipeline broken');
}

// ─────────────────────────────────────────────────────────────────────
// T10: CLAUDE.md and existing T23 test still agree on contract
// ─────────────────────────────────────────────────────────────────────
echo "\n── T10: CLAUDE.md still asserts 'status' (no drift)\n";
$claudePath = __DIR__.'/../CLAUDE.md';
$claudeContents = file_exists($claudePath) ? file_get_contents($claudePath) : '';
$hasStatusDoc = str_contains($claudeContents, '"status": true/false');
$hasSuccessDoc = str_contains($claudeContents, '"success": true');
if ($hasStatusDoc && ! $hasSuccessDoc) {
    record($results, 't10_docs_consistent', 'PASS', "CLAUDE.md still says 'status' as contract");
    $ok('CLAUDE.md consistent with canonical envelope');
} else {
    record($results, 't10_docs_consistent', 'FAIL',
        'status_in_docs='.var_export($hasStatusDoc, true).', success_in_docs='.var_export($hasSuccessDoc, true));
    $fail('CLAUDE.md drift detected');
}

// ─────────────────────────────────────────────────────────────────────
// Summary
// ─────────────────────────────────────────────────────────────────────
$results['finished_at'] = date('Y-m-d H:i:s');
file_put_contents(storage_path('logs/bus_audit_t23_regression.json'),
    json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

echo "\n═══════════════════════════════════════════════════════════════════\n";
echo "  T23 Comprehensive Regression Summary\n";
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
echo "  Detailed results: storage/logs/bus_audit_t23_regression.json\n";
echo "═══════════════════════════════════════════════════════════════════\n\n";

if ($failed === 0) {
    echo "  RESULT: ✅ PASS — T23 envelope drift fully resolved.\n\n";
} else {
    echo "  RESULT: ❌ FAIL — T23 not fully resolved.\n\n";
}
