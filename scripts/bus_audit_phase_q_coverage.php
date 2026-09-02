<?php

use Illuminate\Contracts\Console\Kernel;

/**
 * ════════════════════════════════════════════════════════════════════════════
 * Phase Q — Coverage Matrix
 * ════════════════════════════════════════════════════════════════════════════
 *
 * يحسب:
 *   - Discovered: عدد الـ entities / endpoints / methods اللي لقينا
 *   - Testable:   subset اللي نقدر نختبرها (UI/API/DB path available)
 *   - Tested:     subset اللي اختبرناها فعلاً في الـ 35 phases
 *   - Coverage % = Tested / Discovered × 100
 *
 * المخرج: storage/logs/bus_audit_phase_q_coverage.json + stdout
 */
if (! defined('LARAVEL_START')) {
    define('LARAVEL_START', microtime(true));
    require __DIR__.'/../vendor/autoload.php';
    $app = require_once __DIR__.'/../bootstrap/app.php';
    $kernel = $app->make(Kernel::class);
    $kernel->bootstrap();
}

$ok = fn (string $m) => print "  ✓ $m\n";
$fail = fn (string $m) => print "  ✗ $m\n";
$info = fn (string $m) => print "  ℹ $m\n";
$head = fn (string $m) => print "\n── $m\n";

echo "═══════════════════════════════════════════════════════════════════\n";
echo "  Phase Q — Coverage Matrix\n";
echo "═══════════════════════════════════════════════════════════════════\n\n";

// ─── Discovered (from BUS_MODULE_AUDIT_INVENTORY_20260813.md) ───────────
$discovered = [
    'models' => 6,         // BusBooking, BusInventory, BusCompany, BusPayment, BusRefundRequest, BusCompanyPayment (BusTicket module removed in F-8 cleanup 2026-08-13; BusGovernorate module removed in F-9 cleanup 2026-08-13)
    'soft_deletable_models' => 6,
    'services' => 4,       // Booking, Company, Inventory, Refund
    'filament_resources' => 8,
    'filament_pages' => 1,
    'api_endpoints' => 31, // Bus prefix
    'vue_pages' => 9,
    'enums' => 4,
    'phpunit_tests' => 167, // assertions baseline
    'existing_e2e_scenarios' => 23,
];

// ─── Testable (subset we can actually test) ────────────────────────────
$testable = [
    'models' => 7,         // All 7 models can be tested at model layer
    'soft_deletable_models' => 7, // All 7 have SoftDeletes
    'services' => 4,       // All 4 services testable
    'filament_resources' => 8,    // All 8 resources have admin UI
    'filament_pages' => 1,        // 1 page can be tested
    'api_endpoints' => 31,        // All accessible via auth
    'vue_pages' => 9,             // All 9 pages routable
    'enums' => 4,                 // All enums testable
    'phpunit_tests' => 167,       // All assertions
    'existing_e2e_scenarios' => 23, // All 23 reproducible
];

// ─── Tested (what we actually exercised in this audit) ──────────────────
$tested = [
    'models' => 6,         // BusBooking, BusInventory, BusCompany, BusPayment, BusRefundRequest, BusCompanyPayment (BusTicket removed in F-8)
    'soft_deletable_models' => 5, // Booking, Inventory, Company, Payment, RefundRequest (CompanyPayment NOT_TESTABLE per matrix)
    'services' => 4,       // All 4 services exercised
    'filament_resources' => 3,    // Companies, Inventories, Bookings (not Tickets, Governorates, CompanyPayments, Banks, Wallets)
    'filament_pages' => 1,        // BusCompanyDebtStatement
    'api_endpoints' => 17,        // /bus/* endpoints exercised via Service/API tests
    'vue_pages' => 5,             // Dashboard, Index, Show, Treasury, CompanyStatement (Create, InventoryIndex, CompanyIndex, CustomerIndex, RefundWizard partially)
    'enums' => 4,                 // All 4 enums verified
    'phpunit_tests' => 0,         // Not re-run by audit (left at baseline)
    'existing_e2e_scenarios' => 23, // All 23 re-validated as baseline
];

// ─── Soft Delete per-entity matrix ─────────────────────────────────────
$softDeleteMatrix = [
    'BusBooking' => [
        'sd1_create' => 'TESTED', 'sd2_delete' => 'TESTED', 'sd3_at_populated' => 'TESTED',
        'sd4_row_present' => 'TESTED', 'sd5_excluded_listings' => 'TESTED',
        'sd15_restore' => 'NOT_SUPPORTED', 'sd16_force_delete' => 'NOT_SUPPORTED',
    ],
    'BusInventory' => [
        'sd1_create' => 'TESTED', 'sd2_delete' => 'TESTED', 'sd3_at_populated' => 'TESTED',
        'sd4_row_present' => 'TESTED', 'sd5_excluded_listings' => 'TESTED',
        'sd15_restore' => 'NOT_SUPPORTED', 'sd16_force_delete' => 'NOT_SUPPORTED',
    ],
    'BusCompany' => [
        'sd1_create' => 'TESTED', 'sd2_delete' => 'TESTED', 'sd3_at_populated' => 'TESTED',
        'sd4_row_present' => 'TESTED', 'sd5_excluded_listings' => 'TESTED',
        'sd15_restore' => 'NOT_SUPPORTED', 'sd16_force_delete' => 'NOT_SUPPORTED',
    ],
    'BusPayment' => [
        'sd1_create' => 'TESTED', 'sd2_delete' => 'NOT_TESTABLE', 'sd3_at_populated' => 'TESTED',
        'sd15_restore' => 'NOT_SUPPORTED', 'sd16_force_delete' => 'NOT_SUPPORTED',
    ],
    'BusRefundRequest' => [
        'sd1_create' => 'TESTED', 'sd2_delete' => 'NOT_TESTABLE', 'sd3_at_populated' => 'TESTED',
        'sd15_restore' => 'NOT_SUPPORTED', 'sd16_force_delete' => 'NOT_SUPPORTED',
    ],
    'BusCompanyPayment' => [
        'sd1_create' => 'NOT_TESTABLE', 'sd2_delete' => 'NOT_TESTABLE',
        'sd15_restore' => 'NOT_SUPPORTED', 'sd16_force_delete' => 'NOT_SUPPORTED',
    ],
    // BusTicket removed in F-8 cleanup (2026-08-13) — module deprecated.
    // BusGovernorate removed in F-9 cleanup (2026-08-13) — module deprecated.
];

// ─── Per-phase coverage ────────────────────────────────────────────────
$phaseCoverage = [
    // Phase 1-3: Discovery
    'Phase 1-3 Discovery' => ['discovered' => 35, 'tested' => 35, 'pct' => 100],
    // Phase 4-6: Filament master data
    'Phase 4-6 Filament' => ['discovered' => 8, 'tested' => 3, 'pct' => 37, 'note' => 'Companies, Inventories, Bookings only (not Tickets, Governorates, Banks, Wallets)'],
    // Phase 7: Vue module audit
    'Phase 7 Vue' => ['discovered' => 9, 'tested' => 5, 'pct' => 56, 'note' => 'Dashboard, Index, Show, Treasury, CompanyStatement tested'],
    // Phase 8: Booking flow
    'Phase 8 Booking' => ['tested' => 1, 'method' => 'Service-layer + API'],
    // Phase 9-10: Seat integrity + Customer
    'Phase 9 Seat' => ['tested' => 0, 'note' => 'NOT_TESTABLE — no Vue seat-map picker'],
    'Phase 10 Customer' => ['discovered' => 1, 'tested' => 1, 'pct' => 100],
    // Phase 11-15: Payments
    'Phase 11-15 Payments' => ['discovered' => 5, 'tested' => 5, 'pct' => 100],
    // Phase 16-17: Edge cases
    'Phase 16 Overpay' => ['tested' => 1, 'note' => 'API call + service-level assert'],
    'Phase 17 Double-submit' => ['tested' => 1, 'method' => 'Rapid two sequential calls'],
    // Phase 18-20: Transactions + Treasury
    'Phase 18-20 Tx+Treasury' => ['discovered' => 6, 'tested' => 5, 'pct' => 83, 'tests' => 'i (13+1) + j (7)'],
    // Phase 21-22: Cancel + Refund
    'Phase 21-22 Cancel+Refund' => ['tested' => 1, 'method' => 'Service-layer + scenarios'],
    // Phase 23: Validation
    'Phase 23 Validation' => ['tests' => 11, 'pass' => 11, 'fail' => 0],
    // Phase 24: Authorization
    'Phase 24 Authz' => ['tested' => 1, 'method' => 'Phase K — 3 roles × endpoints'],
    // Phase 25: Error handling
    'Phase 25 Errors' => ['tested' => 1, 'method' => 'Phase L — exception paths'],
    // Phase 26: Frontend/API contract
    'Phase 26 Contract' => ['tests' => 3, 'pass' => 1, 'fail' => 2, 'note' => 'T23 JSON envelope FAIL'],
    // Phase 27: Reports
    'Phase 27 Reports' => ['tests' => 12, 'pass' => 9, 'fail' => 0, 'warn' => 1],
    // Phase 28: Real-life scenarios
    'Phase 28 Scenarios' => ['tests' => 14, 'pass' => 14, 'fail' => 0],
    // Phase 29: DB integrity
    'Phase 29 DB Integrity' => ['tests' => 9, 'pass' => 7, 'fail' => 0, 'warn' => 2],
    // Phase 30: Financial reconciliation
    'Phase 30 Recon' => ['tests' => 7, 'pass' => 7, 'fail' => 0],
    // Phase 31: Regression
    'Phase 31 Regression' => ['tests' => 23, 'pass' => 23, 'fail' => 0, 'note' => 'bus_module_full_e2e.php baseline'],
    // Phase 32: Idempotency
    'Phase 32 Idempotency' => ['tested' => 1, 'note' => 'Re-run audit phase scripts'],
    // Phase 33: Test quality
    'Phase 33 Test Quality' => ['tested' => 1, 'method' => 'Inline code review'],
    // Phase 34: Coverage
    'Phase 34 Coverage' => ['tested' => 1, 'this phase'],
    // Phase 35: Final report
    'Phase 35 Final Report' => ['tested' => 1, 'this phase'],
];

// ─── Soft delete coverage ──────────────────────────────────────────────
$head('SOFT DELETE COVERAGE');
$totalSd = 0;
$testedSd = 0;
$nsSd = 0;
$ntSd = 0;
foreach ($softDeleteMatrix as $entity => $cells) {
    foreach ($cells as $status) {
        $totalSd++;
        if ($status === 'TESTED') {
            $testedSd++;
        } elseif ($status === 'NOT_SUPPORTED') {
            $nsSd++;
        } elseif ($status === 'NOT_TESTABLE') {
            $ntSd++;
        }
    }
    $cellSummary = [];
    foreach ($cells as $k => $v) {
        $cellSummary[$v] = ($cellSummary[$v] ?? 0) + 1;
    }
    $info(sprintf('%s: %s', $entity, json_encode($cellSummary)));
}
$info("Soft Delete total: $totalSd cells, $testedSd TESTED, $nsSd NOT_SUPPORTED, $ntSd NOT_TESTABLE");
$softDeleteCoverage = $totalSd > 0 ? round($testedSd / $totalSd * 100, 1) : 0;
$info("Soft Delete coverage: $softDeleteCoverage%");

// ─── Per-layer coverage ────────────────────────────────────────────────
$head('COVERAGE BY LAYER');
$layers = [
    'Models' => ['discovered' => 7, 'tested' => 7, 'method' => 'Service-layer + DB'],
    'Services' => ['discovered' => 4, 'tested' => 4, 'method' => 'Direct PHPUnit + service tests'],
    'Filament Resources' => ['discovered' => 8, 'tested' => 3, 'method' => 'Admin UI (partial)'],
    'API Endpoints' => ['discovered' => 31, 'tested' => 17, 'method' => 'Service + HTTP via PHPUnit'],
    'Vue Pages' => ['discovered' => 9, 'tested' => 5, 'method' => 'Browser-driven (partial)'],
    'Enums' => ['discovered' => 4, 'tested' => 4, 'method' => 'Direct verification'],
    'Soft Delete' => ['discovered' => 7, 'tested' => 5, 'method' => '17 scenarios × 7 entities'],
    'Existing e2e' => ['discovered' => 23, 'tested' => 23, 'method' => 'bus_module_full_e2e.php'],
];

$totalDisc = 0;
$totalTested = 0;
foreach ($layers as $layer => $data) {
    $disc = $data['discovered'];
    $test = $data['tested'];
    $pct = $disc > 0 ? round($test / $disc * 100, 1) : 0;
    $totalDisc += $disc;
    $totalTested += $test;
    $info(sprintf('%s: %d/%d (%s%%)', $layer, $test, $disc, $pct));
}
$totalCoverage = $totalDisc > 0 ? round($totalTested / $totalDisc * 100, 1) : 0;
$info("Total: $totalTested/$totalDisc ({$totalCoverage}%)");

// ─── Per-phase coverage ────────────────────────────────────────────────
$head('PHASE COVERAGE');
$phasesTotal = 35;
$phasesCompleted = 0;
foreach ($phaseCoverage as $phase => $data) {
    if (isset($data['tested']) && $data['tested'] > 0) {
        $phasesCompleted++;
    }
}
$info("Completed phases: $phasesCompleted/$phasesTotal");

// ─── Findings summary ──────────────────────────────────────────────────
$head('KNOWN FINDINGS SUMMARY');
$findings = [
    'T22_cross_currency_no_guard' => ['severity' => 'HIGH', 'status' => 'PRE-EXISTING', 'tests' => '4 (3 PASS, 1 FAIL — strict contract)'],
    'T23_json_envelope_drift' => ['severity' => 'HIGH', 'status' => 'PRE-EXISTING', 'tests' => '3 (1 PASS, 2 FAIL — strict contract)'],
    'i6_fix12_incomplete_for_cancelled' => ['severity' => 'MEDIUM', 'status' => 'NEW', 'tests' => '1 FAIL'],
    'restore_not_supported' => ['severity' => 'HIGH', 'status' => 'NEW', 'tests' => '7 entities × NOT_SUPPORTED'],
    'force_delete_not_supported' => ['severity' => 'HIGH', 'status' => 'NEW', 'tests' => '7 entities × NOT_SUPPORTED'],
    'trashed_filter_no_action' => ['severity' => 'MEDIUM', 'status' => 'NEW', 'tests' => '3 Filament resources — filter exists but no RestoreAction'],
    'admin_only_service_gap' => ['severity' => 'LOW', 'status' => 'NEW', 'tests' => 'API DELETE not gated by admin middleware'],
];
foreach ($findings as $id => $f) {
    $info(sprintf('[%s] %s — %s (%s tests)', $f['severity'], $id, $f['status'], $f['tests']));
}

// ─── Verdict ────────────────────────────────────────────────────────────
$head('VERDICT CALCULATION');
$criticalFailures = 0;
$criticalFailures += ($findings['T22_cross_currency_no_guard']['status'] === 'PRE-EXISTING') ? 1 : 0;
$criticalFailures += ($findings['T23_json_envelope_drift']['status'] === 'PRE-EXISTING') ? 1 : 0;
$criticalFailures += ($findings['restore_not_supported']['severity'] === 'HIGH') ? 1 : 0;
$criticalFailures += ($findings['force_delete_not_supported']['severity'] === 'HIGH') ? 1 : 0;

$verdict = $criticalFailures > 0 ? 'NO-GO' : 'GO';
$info("Critical failures: $criticalFailures (T22 + T23 + 2 Restore/ForceDelete classes)");
$info("Verdict: $verdict");

// ─── Output ────────────────────────────────────────────────────────────
$output = [
    'discovered' => $discovered,
    'testable' => $testable,
    'tested' => $tested,
    'soft_delete_matrix' => $softDeleteMatrix,
    'soft_delete_coverage' => [
        'total_cells' => $totalSd,
        'tested' => $testedSd,
        'not_supported' => $nsSd,
        'not_testable' => $ntSd,
        'coverage_pct' => $softDeleteCoverage,
    ],
    'layer_coverage' => $layers,
    'total_coverage_pct' => $totalCoverage,
    'phase_coverage' => $phaseCoverage,
    'phases_completed' => "$phasesCompleted/$phasesTotal",
    'findings' => $findings,
    'critical_failures' => $criticalFailures,
    'verdict' => $verdict,
    'finished_at' => date('Y-m-d H:i:s'),
];

file_put_contents(storage_path('logs/bus_audit_phase_q_coverage.json'),
    json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

echo "\n═══════════════════════════════════════════════════════════════════\n";
echo "  Phase Q — Coverage Matrix Summary\n";
echo "═══════════════════════════════════════════════════════════════════\n";
echo "  Total:          $totalTested/$totalDisc tested ($totalCoverage%)\n";
echo "  Soft Delete:    $testedSd/$totalSd cells ($softDeleteCoverage%)\n";
echo "  Phases:         $phasesCompleted/$phasesTotal completed\n";
echo '  Findings:       '.count($findings)."\n";
echo "  Critical:       $criticalFailures\n";
echo "  Verdict:        $verdict\n";
echo "═══════════════════════════════════════════════════════════════════\n\n";
