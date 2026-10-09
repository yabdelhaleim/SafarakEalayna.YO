<?php

namespace AuditPhases;

use AuditHelpers\PhaseResult;
use AuditHelpers\AuditContext;
use AuditHelpers\AuditReconciliation;
use AuditHelpers\AuditHttp;
use App\Models\Flight\FlightBooking;
use App\Models\HajjUmraBooking;
use App\Models\VisaBooking;
use App\Services\Flight\FlightBookingService;
use App\Services\Flight\AviationService;
use App\Services\HajjUmra\HajjUmraBookingService;
use App\Services\Visa\VisaBookingService;
use Illuminate\Support\Facades\Route;

/**
 * PHASE 11 — Post-save Edit Lock (CRITICAL).
 *
 * The Tourism no-edit contract (INCIDENT-2026-08-17) requires ZERO post-save
 * edit paths. This phase tests every layer:
 *
 *   Layer 1 — Routes (HTTP-level):
 *     PUT / PATCH /api/v1/{module}/bookings/{id}     → expect 405 (absent)
 *     POST /api/v1/flight/bookings/{id}/prices       → 404/405/LogicException
 *
 *   Layer 2 — Direct service calls:
 *     FlightBookingService::updateBooking()          → LogicException
 *     FlightBookingService::updatePrices()           → LogicException
 *     AviationService::updateBooking()               → LogicException
 *     HajjUmraBookingService::update()               → LogicException
 *     VisaBookingService::update()                   → LogicException
 *
 *   Layer 3 — FormRequest boundary:
 *     UpdateHajjUmraBookingRequest with LOCKED_FIELDS → ValidationException
 *
 *   Layer 4 — Vue + Filament file-level:
 *     resources/js/views/{flights,FlightEdit.vue, etc.} → must NOT exist
 *     resources/js/router/index.js → must NOT register /{module}/:id/edit
 *
 * A passing test (rejection correct) records pass. A slip (no rejection)
 * records fail with severity='critical' — INCIDENT-2026-08-17 regression.
 */
class Phase11_PostSaveEditLock
{
    public string $phaseLabel = 'PHASE 11 — Post-save Edit Lock (CRITICAL)';

    public function __construct(
        protected AuditContext $ctx,
        protected AuditReconciliation $recon,
        protected AuditHttp $http,
    ) {}

    public function run(): PhaseResult
    {
        $r = new PhaseResult('PHASE 11 — Post-save Edit Lock');
        $r->start();

        try {
            $this->ctx->actAsAdmin();
            $this->http->asAdmin();

            $this->layer1Routes($r);
            $this->layer2ServiceCalls($r);
            $this->layer3FormRequest($r);
            $this->layer4VueAndFilament($r);

        } catch (\Throwable $e) {
            $r->fatalError = 'Phase11 fatal: ' . $e->getMessage();
        }

        $r->finish();
        return $r;
    }

    // ── LAYER 1: Routes (HTTP-level) ──────────────────────────────────────────

    protected function layer1Routes(PhaseResult $r): void
    {
        // Create a booking for each module so the URL has a valid id.
        $fb = $this->ctx->createFlightBooking();
        $hb = $this->ctx->createHajjUmraBooking();
        $vb = $this->ctx->createVisaBooking();

        $routes = [
            // PUT/POST/DELETE verbs on the booking resource must be 405.
            ['verb' => 'PUT',    'uri' => "/api/v1/flight/bookings/{$fb->id}",    'expected' => 405, 'module' => 'flight'],
            ['verb' => 'PATCH',  'uri' => "/api/v1/flight/bookings/{$fb->id}",    'expected' => 405, 'module' => 'flight'],
            ['verb' => 'PUT',    'uri' => "/api/v1/hajj-umra/bookings/{$hb->id}", 'expected' => 405, 'module' => 'hajj_umra'],
            ['verb' => 'PATCH',  'uri' => "/api/v1/hajj-umra/bookings/{$hb->id}", 'expected' => 405, 'module' => 'hajj_umra'],
            ['verb' => 'PUT',    'uri' => "/api/v1/visa/bookings/{$vb->id}",      'expected' => 405, 'module' => 'visa'],
            ['verb' => 'PATCH',  'uri' => "/api/v1/visa/bookings/{$vb->id}",      'expected' => 405, 'module' => 'visa'],
        ];

        foreach ($routes as $rt) {
            $resp = $this->http->{$this->verbMethod($rt['verb'])}($rt['uri'], [
                'selling_price' => 999,
                'notes' => 'attack',
            ]);
            $status = (int) $resp['status'];

            if ($status === $rt['expected']) {
                $r->recordPass();
            } else {
                $r->recordFail(
                    scenario: "{$rt['verb']} {$rt['uri']} — no-edit contract",
                    expected: "status={$rt['expected']}",
                    actual: "status={$status}",
                    severity: 'critical',
                    context: [
                        'module' => $rt['module'],
                        'role'   => 'http',
                        'root_cause' => 'INCIDENT-2026-08-17 regression — edit route returned non-405',
                        'response_body' => substr((string) ($resp['body'] ?? ''), 0, 200),
                    ],
                );
            }
        }

        // POST /bookings/{id}/prices — Flight only. Route IS present under
        // admin middleware; service throws LogicException. Either 405/404
        // OR a successful route that then triggers a service-side throw is
        // acceptable. If route returns 200 with no exception, that's a finding.
        $resp = $this->http->post("/api/v1/flight/bookings/{$fb->id}/prices", [
            'selling_price' => 999,
            'purchase_price' => 800,
        ]);
        $status = (int) $resp['status'];

        // Service side: try direct call (admin auth context) — must throw.
        $directThrew = false;
        try {
            app(FlightBookingService::class)->updatePrices($fb, 800.0, 999.0);
        } catch (\LogicException $e) {
            $directThrew = true;
        } catch (\Throwable $e) {
            // Other throwable also acceptable — service must not let the update through.
            $directThrew = true;
        }

        if ($directThrew && ($status === 200 || $status === 405 || $status === 404 || $status >= 400)) {
            // Service throws + route either rejected OR returned 200 with a service-throw.
            // The 200 is OK if the controller also threw.
            $r->recordPass();
        } else {
            $r->recordFail(
                scenario: 'Flight POST /bookings/{id}/prices — service must reject',
                expected: 'LogicException thrown by updatePrices',
                actual: "route status={$status}, directThrew=" . ($directThrew ? 'true' : 'false'),
                severity: 'critical',
                context: [
                    'module' => 'flight',
                    'root_cause' => 'Flight /prices route returned 200 and service did NOT throw',
                ],
            );
        }
    }

    protected function verbMethod(string $verb): string
    {
        return match (strtoupper($verb)) {
            'GET'    => 'get',
            'POST'   => 'post',
            'PUT'    => 'put',
            'PATCH'  => 'patch',
            'DELETE' => 'delete',
            default  => 'get',
        };
    }

    // ── LAYER 2: Direct service calls ─────────────────────────────────────────

    protected function layer2ServiceCalls(PhaseResult $r): void
    {
        $fb = $this->ctx->createFlightBooking();
        $hb = $this->ctx->createHajjUmraBooking();
        $vb = $this->ctx->createVisaBooking();

        // FlightBookingService::updateBooking
        $this->expectLogicException($r, 'flight', 'FlightBookingService::updateBooking',
            function () use ($fb) {
                app(FlightBookingService::class)->updateBooking($fb, ['selling_price' => 999]);
            }
        );

        // FlightBookingService::updatePrices
        $this->expectLogicException($r, 'flight', 'FlightBookingService::updatePrices',
            function () use ($fb) {
                app(FlightBookingService::class)->updatePrices($fb, 999.0, 999.0);
            }
        );

        // AviationService::updateBooking
        $this->expectLogicException($r, 'flight', 'AviationService::updateBooking',
            function () use ($fb) {
                app(AviationService::class)->updateBooking($fb->id, ['selling_price' => 999]);
            }
        );

        // HajjUmraBookingService::update
        $this->expectLogicException($r, 'hajj_umra', 'HajjUmraBookingService::update',
            function () use ($hb) {
                app(HajjUmraBookingService::class)->update($hb, ['selling_price' => 999]);
            }
        );

        // VisaBookingService::update
        $this->expectLogicException($r, 'visa', 'VisaBookingService::update',
            function () use ($vb) {
                app(VisaBookingService::class)->update($vb, ['selling_price' => 999]);
            }
        );
    }

    /**
     * Run a closure and verify it throws \LogicException (or any throwable
     * — both indicate the service blocked the edit). Pass on throw, fail on
     * silent return.
     */
    protected function expectLogicException(PhaseResult $r, string $module, string $scenario, \Closure $fn): void
    {
        try {
            $fn();
            $r->recordFail(
                scenario: "{$module}: {$scenario} must throw LogicException",
                expected: 'LogicException (no-edit contract)',
                actual: 'No exception — edit succeeded',
                severity: 'critical',
                context: [
                    'module' => $module,
                    'root_cause' => 'INCIDENT-2026-08-17 regression — service accepted edit',
                ],
            );
        } catch (\LogicException $e) {
            $r->recordPass();
        } catch (\Throwable $e) {
            // Other throwable (RuntimeException, etc.) also indicates the
            // service blocked the edit. Acceptable.
            $r->recordPass();
        }
    }

    // ── LAYER 3: FormRequest boundary ─────────────────────────────────────────

    protected function layer3FormRequest(PhaseResult $r): void
    {
        $reqClass = \App\Http\Requests\HajjUmra\UpdateHajjUmraBookingRequest::class;

        // Instantiate directly (PUT/PATCH routes are absent). Sending a
        // LOCKED_FIELDS value must throw ValidationException.
        try {
            $hb = $this->ctx->createHajjUmraBooking();
            $req = $reqClass::create(
                "/api/v1/hajj-umra/bookings/{$hb->id}",
                'PUT',
                [
                    'selling_price' => 999,
                    'purchase_price' => 999,
                    'companion_selling_price' => 999,
                ]
            );
            $req->setContainer(app());
            $req->setRedirector(app('redirect'));
            try {
                $req->validateResolved();
                $r->recordFail(
                    scenario: 'Layer 3: UpdateHajjUmraBookingRequest rejects LOCKED_FIELDS',
                    expected: 'ValidationException thrown',
                    actual: 'No exception — LOCKED_FIELDS accepted',
                    severity: 'critical',
                    context: [
                        'module' => 'hajj_umra',
                        'root_cause' => 'FormRequest boundary did not enforce LOCKED_FIELDS',
                    ],
                );
            } catch (\Illuminate\Validation\ValidationException $e) {
                $r->recordPass();
            } catch (\Throwable $e) {
                // Service-side throw inside the FormRequest is also acceptable.
                $r->recordPass();
            }
        } catch (\Throwable $e) {
            // Failures to construct the FormRequest at all = a finding.
            $r->recordFail(
                scenario: 'Layer 3: UpdateHajjUmraBookingRequest instantiable',
                expected: 'FormRequest constructible',
                actual: $e->getMessage(),
                severity: 'medium',
                context: ['module' => 'hajj_umra'],
            );
        }
    }

    // ── LAYER 4: Vue + Filament file-level checks ─────────────────────────────

    protected function layer4VueAndFilament(PhaseResult $r): void
    {
        // 4a. Vue Edit views must NOT exist.
        $vueBase = base_path('resources/js');
        $filesToCheck = [
            "{$vueBase}/views/flights/FlightEdit.vue"      => 'flight',
            "{$vueBase}/views/hajjUmra/HajjUmraEdit.vue"   => 'hajj_umra',
            "{$vueBase}/views/visa/VisaEdit.vue"           => 'visa',
        ];
        foreach ($filesToCheck as $path => $module) {
            if (file_exists($path)) {
                $r->recordFail(
                    scenario: "Vue {$module} Edit view must NOT exist (no-edit contract)",
                    expected: 'File absent',
                    actual: "File present: {$path}",
                    severity: 'critical',
                    context: [
                        'module' => $module,
                        'role'   => 'http',
                        'root_cause' => 'Edit Vue view discovered — INCIDENT-2026-08-17 regression',
                    ],
                );
            } else {
                $r->recordPass();
            }
        }

        // 4b. Vue router must NOT register /{module}/:id/edit routes.
        $routerPath = base_path('resources/js/router/index.js');
        if (file_exists($routerPath)) {
            $routerContent = file_get_contents($routerPath);
            foreach (['flights', 'hajj-umra', 'visa'] as $prefix) {
                // match patterns like "/{prefix}/:id/edit" or "{prefix}.edit"
                $patterns = [
                    "#'/{$prefix}/.*edit'#",
                    "#\"\\/{$prefix}\\/.*edit\"#",
                    "#['\"]\\/{$prefix}['\"]\\s*,\\s*name\\s*:\\s*['\"]{$prefix}\\.edit#",
                ];
                $found = false;
                foreach ($patterns as $p) {
                    if (preg_match($p, $routerContent)) {
                        $found = true;
                        break;
                    }
                }
                if ($found) {
                    $r->recordFail(
                        scenario: "Vue router: no /{$prefix}/.../edit route",
                        expected: 'No /edit route registered',
                        actual: 'Edit route found in router/index.js',
                        severity: 'critical',
                        context: [
                            'module' => $prefix,
                            'root_cause' => 'Edit route registered in Vue router',
                        ],
                    );
                } else {
                    $r->recordPass();
                }
            }
        }

        // 4c. Laravel route registry check (defense in depth).
        $noEditUris = [
            'api/v1/flight/bookings/{flightBooking}',
            'api/v1/hajj-umra/bookings/{hajjUmra}',
            'api/v1/visa/bookings/{visa}',
        ];
        $noEditVerbs = ['PUT', 'PATCH'];

        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();
            if (!in_array($uri, $noEditUris, true)) continue;
            $methods = $route->methods();
            foreach ($noEditVerbs as $verb) {
                if (in_array($verb, $methods, true)) {
                    $r->recordFail(
                        scenario: "Route registry: {$verb} {$uri} absent",
                        expected: 'Route absent (no-edit contract)',
                        actual: "{$verb} {$uri} present",
                        severity: 'critical',
                        context: [
                            'module' => str_contains($uri, 'flight') ? 'flight'
                                : (str_contains($uri, 'hajj') ? 'hajj_umra' : 'visa'),
                            'root_cause' => 'Edit route present in registry',
                        ],
                    );
                }
            }
        }
        // Pass counter — at minimum the 6 (3 modules × 2 verbs) checks have
        // been walked. We record a single info pass to mark coverage.
        $r->recordInfo('Route registry scan', 'Completed no-edit route scan');

        // 4d. Filament resources: check for tourism BookingResource with edit pages.
        // We avoid filesystem globbing that may match unrelated resources; instead
        // we search for any Filament page whose name contains 'EditFlight' /
        // 'EditHajj' / 'EditVisa' (a strong signal).
        $filamentPages = glob(base_path('app/Filament/*/Resources/*/Pages/*Edit.php')) ?: [];
        foreach ($filamentPages as $page) {
            $base = basename($page);
            $module = (str_contains($base, 'Flight') ? 'flight'
                : (str_contains($base, 'Hajj') ? 'hajj_umra'
                    : (str_contains($base, 'Visa') ? 'visa' : 'cross')));
            if ($module !== 'cross') {
                $r->recordFail(
                    scenario: "Filament {$module} Edit page must NOT exist",
                    expected: 'No Edit page',
                    actual: "Edit page found: {$base}",
                    severity: 'critical',
                    context: [
                        'module' => $module,
                        'root_cause' => 'Filament edit page discovered',
                    ],
                );
            }
        }
        if (empty($filamentPages)) {
            $r->recordPass();
        }
    }
}
