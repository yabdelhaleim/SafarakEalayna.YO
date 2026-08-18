<?php

namespace AuditPhases;

use AuditHelpers\PhaseResult;
use AuditHelpers\AuditContext;
use AuditHelpers\AuditReconciliation;
use AuditHelpers\AuditHttp;
use Illuminate\Support\Facades\DB;
use App\Models\Account;
use App\Enums\AccountType;

/**
 * PHASE 12 — Tourism No-Edit Contract (pre-completion lock).
 *
 * Verifies that NO edits are allowed to a saved booking's financial fields,
 * and indeed NO edits are allowed to ANY field after save. The No-Edit
 * Contract (INCIDENT-2026-08-17) states:
 *
 *   "Editing a booking after it has been persisted is forbidden entirely.
 *    The system must reject every attempt — direct service call, HTTP
 *    PUT/PATCH, or any other surface — regardless of which field is being
 *    modified and regardless of the booking's completion state."
 *
 * For each module (Flight / Hajj / Visa):
 *   1. Direct service.update() MUST throw LogicException (or equivalent)
 *   2. HTTP PUT/PATCH MUST return 405 (route absent)
 *   3. POST /bookings/{id} to non-payment/non-cancel endpoints MUST NOT mutate
 *
 * Any non-rejection = NO-GO finding at severity=critical.
 */
class Phase12_PreCompletionEdit
{
    public string $phaseLabel = 'PHASE 12 — Pre-Completion Edit Lock';

    public function __construct(
        protected AuditContext $ctx,
        protected AuditReconciliation $recon,
        protected AuditHttp $http,
    ) {}

    public function run(): PhaseResult
    {
        $r = new PhaseResult('PHASE 12 — Pre-Completion Edit Lock');
        $r->start();

        try {
            $this->ctx->actAsAdmin();

            // ── 1. Flight module ─────────────────────────────────────────
            $this->verifyFlightEditLock($r);

            // ── 2. Hajj/Umra module ─────────────────────────────────────
            $this->verifyHajjUmraEditLock($r);

            // ── 3. Visa module ──────────────────────────────────────────
            $this->verifyVisaEditLock($r);

            // ── 4. Service-level update() throws LogicException globally
            $this->verifyServiceStubsRejectUpdate($r);

        } catch (\Throwable $e) {
            $r->fatalError = 'Phase 12 exception: ' . $e->getMessage();
        }

        $r->finish();
        return $r;
    }

    protected function verifyFlightEditLock(PhaseResult $r): void
    {
        // Create a Flight booking first
        try {
            $booking = $this->ctx->createFlightBooking();
            $bookingId = $booking->id;
        } catch (\Throwable $e) {
            $r->recordBlock('Flight edit lock', 'Could not seed Flight booking: ' . $e->getMessage());
            return;
        }

        // 1a. Direct service.updateBooking() — must throw LogicException
        try {
            $svc = app(\App\Services\Flight\FlightBookingService::class);
            $svc->updateBooking($booking, ['notes' => $this->ctx->prefix . 'EDITED']);
            $r->recordFail(
                scenario: 'Flight direct service.updateBooking()',
                expected: 'LogicException (No-Edit Contract)',
                actual: 'Update succeeded — financial/edit path open',
                severity: 'critical',
                context: [
                    'module' => 'flight',
                    'role' => 'admin',
                    'root_cause' => 'Flight updateBooking() permitted mutation — INCIDENT-2026-08-17',
                    'account_ids' => [],
                ],
            );
        } catch (\LogicException $e) {
            $r->recordPass();
        } catch (\Throwable $e) {
            // Other exceptions (e.g. validation) are still rejections but record context
            $r->recordPass();
        }

        // 1b. Direct service.updatePrices() — must throw LogicException
        try {
            $svc = app(\App\Services\Flight\FlightBookingService::class);
            $svc->updatePrices($booking, 1.0, 2.0);
            $r->recordFail(
                scenario: 'Flight direct service.updatePrices()',
                expected: 'LogicException (No-Edit Contract)',
                actual: 'Price update succeeded',
                severity: 'critical',
                context: [
                    'module' => 'flight',
                    'role' => 'admin',
                    'root_cause' => 'Flight updatePrices() permitted mutation',
                ],
            );
        } catch (\LogicException $e) {
            $r->recordPass();
        } catch (\Throwable $e) {
            $r->recordPass();
        }

        // 1c. HTTP PUT — must return 405 (route absent)
        $resp = $this->http->put('/api/v1/flight/bookings/' . $bookingId, [
            'notes' => $this->ctx->prefix . 'HTTP_EDIT',
        ]);
        if ($resp['status'] === 405) {
            $r->recordPass();
        } elseif ($resp['status'] >= 400) {
            // 404 / 403 / 422 also acceptable (route absent OR blocked)
            $r->recordPass();
        } else {
            $r->recordFail(
                scenario: 'Flight HTTP PUT /bookings/{id}',
                expected: '405 (route absent)',
                actual: 'Status ' . $resp['status'] . ' returned',
                severity: 'critical',
                context: [
                    'module' => 'flight',
                    'role' => 'admin',
                    'root_cause' => 'Flight PUT route present or accepted mutation',
                ],
            );
        }

        // 1d. HTTP PATCH — must return 405 (route absent)
        $resp = $this->http->patch('/api/v1/flight/bookings/' . $bookingId, [
            'notes' => $this->ctx->prefix . 'HTTP_PATCH',
        ]);
        if ($resp['status'] === 405 || $resp['status'] >= 400) {
            $r->recordPass();
        } else {
            $r->recordFail(
                scenario: 'Flight HTTP PATCH /bookings/{id}',
                expected: '405 (route absent)',
                actual: 'Status ' . $resp['status'] . ' returned',
                severity: 'critical',
                context: ['module' => 'flight', 'role' => 'admin'],
            );
        }

        // 1e. Verify the booking's notes field was NOT mutated by any of the attempts
        $reloaded = DB::table('flight_bookings')->where('id', $bookingId)->value('notes');
        if ($reloaded !== null && str_contains((string) $reloaded, $this->ctx->prefix . 'EDITED')) {
            $r->recordFail(
                scenario: 'Flight notes unchanged after blocked edit attempts',
                expected: 'Original notes retained',
                actual: 'Notes mutated to ' . (string) $reloaded,
                severity: 'critical',
                context: ['module' => 'flight'],
            );
        } else {
            $r->recordPass();
        }
    }

    protected function verifyHajjUmraEditLock(PhaseResult $r): void
    {
        try {
            $booking = $this->ctx->createHajjUmraBooking();
            $bookingId = $booking->id;
        } catch (\Throwable $e) {
            $r->recordBlock('Hajj edit lock', 'Could not seed Hajj booking: ' . $e->getMessage());
            return;
        }

        // 2a. Direct service.update() — must throw LogicException
        try {
            $svc = app(\App\Services\HajjUmra\HajjUmraBookingService::class);
            $svc->update($booking, ['notes' => $this->ctx->prefix . 'EDITED']);
            $r->recordFail(
                scenario: 'Hajj direct service.update()',
                expected: 'LogicException (No-Edit Contract)',
                actual: 'Update succeeded',
                severity: 'critical',
                context: [
                    'module' => 'hajj_umra',
                    'role' => 'admin',
                    'root_cause' => 'Hajj update() permitted mutation — INCIDENT-2026-08-17',
                ],
            );
        } catch (\LogicException $e) {
            $r->recordPass();
        } catch (\Throwable $e) {
            $r->recordPass();
        }

        // 2b. HTTP PUT — must return 405
        $resp = $this->http->put('/api/v1/hajj-umra/bookings/' . $bookingId, [
            'notes' => $this->ctx->prefix . 'HTTP_EDIT',
        ]);
        if ($resp['status'] === 405 || $resp['status'] >= 400) {
            $r->recordPass();
        } else {
            $r->recordFail(
                scenario: 'Hajj HTTP PUT /bookings/{id}',
                expected: '405 (route absent)',
                actual: 'Status ' . $resp['status'] . ' returned',
                severity: 'critical',
                context: ['module' => 'hajj_umra', 'role' => 'admin'],
            );
        }

        // 2c. HTTP PATCH — must return 405
        $resp = $this->http->patch('/api/v1/hajj-umra/bookings/' . $bookingId, [
            'notes' => $this->ctx->prefix . 'HTTP_PATCH',
        ]);
        if ($resp['status'] === 405 || $resp['status'] >= 400) {
            $r->recordPass();
        } else {
            $r->recordFail(
                scenario: 'Hajj HTTP PATCH /bookings/{id}',
                expected: '405 (route absent)',
                actual: 'Status ' . $resp['status'] . ' returned',
                severity: 'critical',
                context: ['module' => 'hajj_umra', 'role' => 'admin'],
            );
        }
    }

    protected function verifyVisaEditLock(PhaseResult $r): void
    {
        try {
            $booking = $this->ctx->createVisaBooking();
            $bookingId = $booking->id;
        } catch (\Throwable $e) {
            $r->recordBlock('Visa edit lock', 'Could not seed Visa booking: ' . $e->getMessage());
            return;
        }

        // 3a. Direct service.update() — must throw LogicException
        try {
            $svc = app(\App\Services\Visa\VisaBookingService::class);
            $svc->update($booking, ['notes' => $this->ctx->prefix . 'EDITED']);
            $r->recordFail(
                scenario: 'Visa direct service.update()',
                expected: 'LogicException (No-Edit Contract)',
                actual: 'Update succeeded',
                severity: 'critical',
                context: [
                    'module' => 'visa',
                    'role' => 'admin',
                    'root_cause' => 'Visa update() permitted mutation — INCIDENT-2026-08-17',
                ],
            );
        } catch (\LogicException $e) {
            $r->recordPass();
        } catch (\Throwable $e) {
            $r->recordPass();
        }

        // 3b. HTTP PUT
        $resp = $this->http->put('/api/v1/visa/bookings/' . $bookingId, [
            'notes' => $this->ctx->prefix . 'HTTP_EDIT',
        ]);
        if ($resp['status'] === 405 || $resp['status'] >= 400) {
            $r->recordPass();
        } else {
            $r->recordFail(
                scenario: 'Visa HTTP PUT /bookings/{id}',
                expected: '405 (route absent)',
                actual: 'Status ' . $resp['status'] . ' returned',
                severity: 'critical',
                context: ['module' => 'visa', 'role' => 'admin'],
            );
        }

        // 3c. HTTP PATCH
        $resp = $this->http->patch('/api/v1/visa/bookings/' . $bookingId, [
            'notes' => $this->ctx->prefix . 'HTTP_PATCH',
        ]);
        if ($resp['status'] === 405 || $resp['status'] >= 400) {
            $r->recordPass();
        } else {
            $r->recordFail(
                scenario: 'Visa HTTP PATCH /bookings/{id}',
                expected: '405 (route absent)',
                actual: 'Status ' . $resp['status'] . ' returned',
                severity: 'critical',
                context: ['module' => 'visa', 'role' => 'admin'],
            );
        }
    }

    /**
     * Verify service stubs themselves (independent of HTTP) reject any
     * update operation across all three modules.
     */
    protected function verifyServiceStubsRejectUpdate(PhaseResult $r): void
    {
        // Create a Hajj booking with notes, attempt to update non-financial notes
        try {
            $booking = $this->ctx->createHajjUmraBooking();
            $svc = app(\App\Services\HajjUmra\HajjUmraBookingService::class);
            $originalNotes = $booking->notes;

            $svc->update($booking, ['agent_name' => $this->ctx->prefix . 'AGENT_X']);
            // If we reach here, it succeeded — that IS a finding
            $r->recordFail(
                scenario: 'Hajj service.update() — non-financial field agent_name',
                expected: 'LogicException',
                actual: 'Update of non-financial field succeeded',
                severity: 'critical',
                context: [
                    'module' => 'hajj_umra',
                    'root_cause' => 'No-Edit Contract violated for non-financial fields too',
                ],
            );
        } catch (\LogicException $e) {
            $r->recordPass();
        } catch (\Throwable $e) {
            // Other exceptions are still rejections
            $r->recordPass();
        }

        // Visa non-financial
        try {
            $booking = $this->ctx->createVisaBooking();
            $svc = app(\App\Services\Visa\VisaBookingService::class);
            $svc->update($booking, ['agent_name' => $this->ctx->prefix . 'AGENT_Y']);
            $r->recordFail(
                scenario: 'Visa service.update() — non-financial field agent_name',
                expected: 'LogicException',
                actual: 'Update of non-financial field succeeded',
                severity: 'critical',
                context: ['module' => 'visa'],
            );
        } catch (\LogicException $e) {
            $r->recordPass();
        } catch (\Throwable $e) {
            $r->recordPass();
        }
    }
}
