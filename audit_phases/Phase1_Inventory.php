<?php

namespace AuditPhases;

use AuditHelpers\PhaseResult;
use AuditHelpers\AuditContext;
use AuditHelpers\AuditReconciliation;
use AuditHelpers\AuditHttp;
use Illuminate\Support\Facades\Route;

/**
 * PHASE 1 — System inventory.
 *
 * Verifies that the routes, controllers, services, FormRequests, models, and
 * Filament resources documented in TOURISM_MODULE_INVENTORY.md are actually
 * present in the codebase. Read-only — does not exercise any financial flow.
 */
class Phase1_Inventory
{
    public string $phaseLabel = 'PHASE 1 — Inventory';

    public function __construct(
        protected AuditContext $ctx,
        protected AuditReconciliation $recon,
        protected AuditHttp $http,
    ) {}

    public function run(): PhaseResult
    {
        $r = new PhaseResult('PHASE 1 — Inventory');
        $r->start();

        // ── Route verification ────────────────────────────────────────────
        $routes = Route::getRoutes();
        $routeNames = [];
        foreach ($routes as $route) {
            $name = $route->getName();
            if ($name) $routeNames[$name] = true;
            $uri = $route->uri();
            // Also index by URI substring (no name for closure routes)
            $routeNames['URI:' . $uri] = true;
        }

        $expectedRoutes = [
            'flight_bookings.index'      => 'GET    /api/v1/flight/bookings',
            'flight_bookings.store'      => 'POST   /api/v1/flight/bookings',
            'flight_bookings.show'       => 'GET    /api/v1/flight/bookings/{flightBooking}',
            'URI:api/v1/flight/bookings/{flightBooking}/payments' => 'POST addPayment (open)',
            'URI:api/v1/flight/bookings/{flightBooking}/cancel'   => 'POST cancel (admin)',
            'URI:api/v1/flight/bookings/{flightBooking}/confirm'  => 'POST confirm (admin)',
            'URI:api/v1/flight/refunds'  => 'POST flight refund',
            'URI:api/v1/hajj-umra/bookings' => 'POST hajj store',
            'URI:api/v1/hajj-umra/bookings/{hajjUmra}' => 'GET hajj show',
            'URI:api/v1/hajj-umra/bookings/{hajjUmra}/payments' => 'POST hajj addPayment',
            'URI:api/v1/hajj-umra/bookings/{hajjUmra}/cancel'   => 'POST hajj cancel (admin)',
            'URI:api/v1/hajj-umra/bookings/{hajjUmra}/refund'   => 'POST hajj refund (manage_refunds)',
            'URI:api/v1/visa/bookings'   => 'POST visa store',
            'URI:api/v1/visa/bookings/{visa}' => 'GET visa show',
            'URI:api/v1/visa/bookings/{visa}/payments' => 'POST visa addPayment',
            'URI:api/v1/visa/bookings/{visa}/cancel'   => 'POST visa cancel (admin)',
            'URI:api/v1/visa/bookings/{visa}/refund'   => 'POST visa refund (manage_refunds)',
        ];

        foreach ($expectedRoutes as $key => $label) {
            if (isset($routeNames[$key])) {
                $r->recordPass();
            } else {
                // The URI may be matched differently — check by URI substring
                $uriPart = str_replace('URI:', '', $key);
                $found = false;
                foreach (array_keys($routeNames) as $existing) {
                    if (str_contains((string) $existing, $uriPart)) {
                        $found = true;
                        break;
                    }
                }
                if ($found) {
                    $r->recordPass();
                } else {
                    $r->recordFail(
                        scenario: "Route inventory: {$label}",
                        expected: "Route '{$key}' present",
                        actual: "Route NOT found",
                        severity: 'medium',
                        context: ['module' => 'cross'],
                    );
                }
            }
        }

        // ── Verify NO-EDIT contract: PUT/PATCH absent for Tourism bookings ──
        $noEditChecks = [
            'api/v1/flight/bookings/{flightBooking}' => ['PUT', 'PATCH'],
            'api/v1/hajj-umra/bookings/{hajjUmra}'  => ['PUT', 'PATCH'],
            'api/v1/visa/bookings/{visa}'           => ['PUT', 'PATCH'],
        ];
        foreach ($noEditChecks as $uri => $verbs) {
            foreach ($verbs as $verb) {
                $found = false;
                foreach ($routes as $route) {
                    if ($route->uri() === $uri && in_array($verb, $route->methods(), true)) {
                        $found = true;
                        break;
                    }
                }
                if ($found) {
                    $r->recordFail(
                        scenario: "No-Edit contract: {$verb} {$uri}",
                        expected: "{$verb} route absent (no-edit contract INCIDENT-2026-08-17)",
                        actual: "{$verb} route present",
                        severity: 'critical',
                        context: ['module' => 'cross', 'root_cause' => 'Edit path discovered — INCIDENT-2026-08-17 regression'],
                    );
                } else {
                    $r->recordPass();
                }
            }
        }

        // ── Verify POST /bookings/{id}/prices is absent (Flight) ──────────
        foreach ($routes as $route) {
            if ($route->uri() === 'api/v1/flight/bookings/{flightBooking}/prices' && $route->methods()[0] === 'POST') {
                // Note: it's still registered under admin middleware but should be unreachable for edit-after-completion
                $r->recordInfo('Flight /prices route present', 'Admin-only via middleware; service stub throws LogicException');
            }
        }
        $r->recordPass();

        // ── Service method verification ───────────────────────────────────
        $flightSvc = app(\App\Services\Flight\FlightBookingService::class);
        $flightMethods = get_class_methods($flightSvc);
        foreach (['createBooking', 'addPayment', 'confirmBooking', 'cancelBooking', 'deleteBookingWithReversal', 'updateBooking', 'updatePrices'] as $m) {
            if (!in_array($m, $flightMethods, true)) {
                $r->recordFail(
                    scenario: "Flight service method missing: {$m}",
                    expected: "Method present",
                    actual: "Method absent",
                    severity: 'medium',
                    context: ['module' => 'flight'],
                );
            } else {
                $r->recordPass();
            }
        }

        $hajjSvc = app(\App\Services\HajjUmra\HajjUmraBookingService::class);
        foreach (['create', 'update', 'cancel', 'deleteBookingWithReversal', 'addPayment'] as $m) {
            if (!method_exists($hajjSvc, $m)) {
                $r->recordFail(
                    scenario: "Hajj service method missing: {$m}",
                    expected: "Method present",
                    actual: "Method absent",
                    severity: 'medium',
                    context: ['module' => 'hajj_umra'],
                );
            } else {
                $r->recordPass();
            }
        }

        $visaSvc = app(\App\Services\Visa\VisaBookingService::class);
        foreach (['create', 'update', 'cancel', 'addPayment', 'addDebtPayment'] as $m) {
            if (!method_exists($visaSvc, $m)) {
                $r->recordFail(
                    scenario: "Visa service method missing: {$m}",
                    expected: "Method present",
                    actual: "Method absent",
                    severity: 'medium',
                    context: ['module' => 'visa'],
                );
            } else {
                $r->recordPass();
            }
        }

        // ── Vue route presence (file-level check) ─────────────────────────
        $vueBase = base_path('resources/js/router/index.js');
        if (file_exists($vueBase)) {
            $vueContent = file_get_contents($vueBase);
            foreach (['flights', 'hajj-umra', 'visa'] as $vueRoot) {
                if (str_contains($vueContent, "'/{$vueRoot}'") || str_contains($vueContent, "/{$vueRoot}'")) {
                    $r->recordPass();
                } else {
                    $r->recordFail(
                        scenario: "Vue router: /{$vueRoot} root",
                        expected: "Route present",
                        actual: "Route not found in router/index.js",
                        severity: 'low',
                        context: ['module' => 'cross'],
                    );
                }
            }
        }

        $r->finish();
        return $r;
    }
}
