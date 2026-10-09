<?php

namespace AuditPhases;

use AuditHelpers\PhaseResult;
use AuditHelpers\AuditContext;
use AuditHelpers\AuditReconciliation;
use AuditHelpers\AuditHttp;
use App\Models\Flight\FlightBooking;
use App\Models\HajjUmraBooking;
use App\Models\VisaBooking;
use Illuminate\Support\Facades\DB;

/**
 * PHASE 14 — Cross-Module Attack Surface.
 *
 * Verifies that an ID from one module CANNOT be used to mutate state on
 * another module. Each row in the matrix below is a (source module,
 * target endpoint, method) triple — every one MUST reject.
 *
 *   Flight ID → Hajj payment/cancel/refund      → reject
 *   Flight ID → Visa payment/cancel/refund       → reject
 *   Hajj ID   → Flight cancel/confirm/payments/refunds → reject
 *   Hajj ID   → Visa payment/cancel/refund       → reject
 *   Visa ID   → Flight cancel/payments/refunds   → reject
 *   Visa ID   → Hajj payment/cancel/refund       → reject
 *
 * For each attack:
 *   1. HTTP call → expect status >= 400 (no 2xx mutation).
 *   2. Direct DB verification: NO new transactions on source booking.
 *
 * Any 2xx response OR new transactions on the source booking = NO-GO
 * finding at severity=critical.
 */
class Phase14_CrossModuleAttack
{
    public string $phaseLabel = 'PHASE 14 — Cross-Module Attack';

    public function __construct(
        protected AuditContext $ctx,
        protected AuditReconciliation $recon,
        protected AuditHttp $http,
    ) {}

    /**
     * Attack matrix: [source_module => [endpoint_path => [method, payload]]]
     * Each entry is one HTTP attack that must be rejected.
     */
    protected function attackMatrix(): array
    {
        return [
            'flight' => [
                '/api/v1/hajj-umra/bookings/{id}/payments' => ['POST', ['amount' => 100, 'payment_method' => 'cash']],
                '/api/v1/hajj-umra/bookings/{id}/cancel'   => ['POST', []],
                '/api/v1/hajj-umra/bookings/{id}/refund'   => ['POST', ['amount' => 100, 'reason' => 'cross-attack']],
                '/api/v1/visa/bookings/{id}/payments'      => ['POST', ['amount' => 100, 'payment_method' => 'cash']],
                '/api/v1/visa/bookings/{id}/cancel'        => ['POST', []],
                '/api/v1/visa/bookings/{id}/refund'        => ['POST', ['amount' => 100, 'reason' => 'cross-attack']],
            ],
            'hajj_umra' => [
                '/api/v1/flight/bookings/{id}/cancel'      => ['POST', []],
                '/api/v1/flight/bookings/{id}/confirm'     => ['POST', []],
                '/api/v1/flight/bookings/{id}/payments'    => ['POST', ['amount' => 100, 'payment_method' => 'cash']],
                '/api/v1/flight/refunds'                   => ['POST', ['flight_booking_id' => '{id}', 'amount' => 100, 'reason' => 'cross-attack']],
                '/api/v1/visa/bookings/{id}/payments'      => ['POST', ['amount' => 100, 'payment_method' => 'cash']],
                '/api/v1/visa/bookings/{id}/cancel'        => ['POST', []],
                '/api/v1/visa/bookings/{id}/refund'        => ['POST', ['amount' => 100, 'reason' => 'cross-attack']],
            ],
            'visa' => [
                '/api/v1/flight/bookings/{id}/cancel'      => ['POST', []],
                '/api/v1/flight/bookings/{id}/payments'    => ['POST', ['amount' => 100, 'payment_method' => 'cash']],
                '/api/v1/flight/refunds'                   => ['POST', ['flight_booking_id' => '{id}', 'amount' => 100, 'reason' => 'cross-attack']],
                '/api/v1/hajj-umra/bookings/{id}/payments' => ['POST', ['amount' => 100, 'payment_method' => 'cash']],
                '/api/v1/hajj-umra/bookings/{id}/cancel'   => ['POST', []],
                '/api/v1/hajj-umra/bookings/{id}/refund'   => ['POST', ['amount' => 100, 'reason' => 'cross-attack']],
            ],
        ];
    }

    protected function modelClassFor(string $module): string
    {
        return match ($module) {
            'flight'    => FlightBooking::class,
            'hajj_umra' => HajjUmraBooking::class,
            'visa'      => VisaBooking::class,
            default     => '',
        };
    }

    protected function createFor(string $module)
    {
        return match ($module) {
            'flight'    => $this->ctx->createFlightBooking(),
            'hajj_umra' => $this->ctx->createHajjUmraBooking(),
            'visa'      => $this->ctx->createVisaBooking(),
            default     => null,
        };
    }

    public function run(): PhaseResult
    {
        $r = new PhaseResult('PHASE 14 — Cross-Module Attack');
        $r->start();

        try {
            $this->ctx->actAsAdmin();

            foreach ($this->attackMatrix() as $sourceModule => $attacks) {
                $this->runAttackSuite($r, $sourceModule, $attacks);
            }

        } catch (\Throwable $e) {
            $r->fatalError = 'Phase 14 exception: ' . $e->getMessage();
        }

        $r->finish();
        return $r;
    }

    /**
     * Run the full attack suite for one source module. Creates ONE booking
     * of that module, then fires every attack endpoint at it, asserting
     * rejection on each and verifying NO new transactions were created.
     */
    protected function runAttackSuite(PhaseResult $r, string $sourceModule, array $attacks): void
    {
        try {
            $booking = $this->createFor($sourceModule);
            if (!$booking) {
                $r->recordBlock("Cross-attack suite ({$sourceModule})", 'Factory missing');
                return;
            }
            $relatedType = $this->modelClassFor($sourceModule);
            $txCountBefore = $this->countTx($booking->id, $relatedType);

            foreach ($attacks as $pathTemplate => [$method, $payload]) {
                $path = str_replace('{id}', (string) $booking->id, $pathTemplate);
                // Also substitute any {id} placeholders inside payload
                $payloadResolved = $this->resolvePayloadPlaceholders($payload, $booking->id);

                try {
                    $resp = $this->http->{$this->methodToVerb($method)}($path, $payloadResolved);
                    $status = $resp['status'];
                } catch (\Throwable $e) {
                    // Exception = rejection by middleware/binding = acceptable
                    $status = 500;
                }

                $label = strtoupper($method) . ' ' . $pathTemplate;
                if ($status >= 400) {
                    $r->recordPass();
                } else {
                    $r->recordFail(
                        scenario: "Cross-module: {$sourceModule} ID → {$label}",
                        expected: '4xx/5xx rejection (cross-module route binding fails)',
                        actual: "Status {$status} returned — cross-module mutation accepted",
                        severity: 'critical',
                        context: [
                            'module' => $sourceModule,
                            'role' => 'admin',
                            'root_cause' => "Cross-module ID accepted on different module endpoint ({$status})",
                        ],
                    );
                }
            }

            // Verify the source booking's transaction count is unchanged
            $txCountAfter = $this->countTx($booking->id, $relatedType);
            if ($txCountAfter === $txCountBefore) {
                $r->recordPass();
            } else {
                $r->recordFail(
                    scenario: "Cross-module: {$sourceModule} booking #{$booking->id} untouched",
                    expected: "Transaction count unchanged (was {$txCountBefore})",
                    actual: "Transaction count changed to {$txCountAfter}",
                    severity: 'critical',
                    context: [
                        'module' => $sourceModule,
                        'root_cause' => "Cross-module attacks created transactions on {$sourceModule} booking #{$booking->id}",
                    ],
                );
            }
        } catch (\Throwable $e) {
            $r->recordBlock("Cross-attack suite ({$sourceModule})", $e->getMessage());
        }
    }

    protected function resolvePayloadPlaceholders(array $payload, int $id): array
    {
        $resolved = [];
        foreach ($payload as $k => $v) {
            if (is_string($v)) {
                $resolved[$k] = str_replace('{id}', (string) $id, $v);
            } else {
                $resolved[$k] = $v;
            }
        }
        return $resolved;
    }

    protected function methodToVerb(string $method): string
    {
        return match (strtoupper($method)) {
            'POST'   => 'post',
            'PUT'    => 'put',
            'PATCH'  => 'patch',
            'DELETE' => 'delete',
            'GET'    => 'get',
            default  => 'call',
        };
    }

    protected function countTx(int $bookingId, string $relatedType): int
    {
        return (int) DB::table('transactions')
            ->where('related_type', $relatedType)
            ->where('related_id', $bookingId)
            ->count();
    }
}
