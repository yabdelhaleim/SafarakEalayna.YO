<?php

namespace App\Services\Finance;

use App\Models\AuditLog;
use App\Models\RefundAuditLog;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

/**
 * Tourism Employee Refund audit trail — atomic, dual-write, mandatory helper.
 *
 * Per the audit spec:
 *   "The refund audit data must be stored persistently in the database."
 *   "A Tourism refund MUST NOT financially commit unless its mandatory
 *    refund_audit_logs record is successfully persisted."
 *   "The same atomicity guarantee must apply to the mandatory generic
 *    audit_logs record."
 *
 * Per user decision:
 *   Persist to BOTH:
 *     1. `refund_audit_logs` — refund-domain queryable view
 *     2. `audit_logs` (action='refund.processed') — generic activity timeline
 *
 * INVARIANT 1 (Actor identity):
 *   The actor (user_id + user_name) is ALWAYS derived from the
 *   authenticated backend user via Auth::id(). The frontend payload
 *   and `$params['user_id']` are NEVER trusted. If there is no
 *   authenticated user, this helper throws — never returns null.
 *
 * INVARIANT 2 (Atomicity):
 *   This helper performs NO exception swallowing. If either
 *   `refund_audit_logs` or `audit_logs` insert fails, the exception
 *   propagates to the caller.
 *
 *   The caller MUST be running inside a DB::transaction closure. When
 *   the exception bubbles up, Laravel's transaction wrapper rolls back
 *   the entire transaction — including all financial mutations that
 *   happened BEFORE this call.
 *
 *   This is the ONLY way to satisfy:
 *     "A Tourism refund MUST NOT financially commit unless its mandatory
 *      refund_audit_logs record is successfully persisted."
 *
 * CALLER CONTRACT:
 *   Must run inside DB::transaction(function () { ... }).
 *   Must NOT wrap this call in a try/catch that swallows the exception.
 *
 * @fix 2026-08-17 (EMP_REFUND_ATOMICITY_FIX_20260817)
 *       Removed silent exception swallowing. Both audit inserts are now
 *       mandatory and atomic with the surrounding financial transaction.
 */
class RefundAuditLogger
{
    /**
     * Persist the dual refund audit trail (refund_audit_logs + audit_logs).
     *
     * Both writes are MANDATORY. If either fails, the exception propagates
     * to the caller — which is expected to be inside a DB::transaction
     * closure — and the entire transaction rolls back.
     *
     * Event semantics (Tourism Employee Refund audit spec):
     *   - 'refund.requested' — write BEFORE the financial mutation (best-effort
     *     observability; not in a transaction). Captures the attempt.
     *   - 'refund.processed' — write INSIDE the same DB::transaction() as the
     *     financial commit. Failure rolls back the entire refund.
     *   - 'refund.reversed'  — write INSIDE the same DB::transaction() as the
     *     reversal mutation. Failure rolls back the entire reversal.
     *
     * @param  array{
     *     module: string,
     *     booking_id: int|null,
     *     booking_reference?: string|null,
     *     customer_id?: int|null,
     *     customer_name?: string|null,
     *     refund_amount: float,
     *     currency?: string,
     *     paid_amount_before?: float,
     *     previously_refunded?: float,
     *     remaining_refundable?: float,
     *     reason?: string|null,
     *     transaction_id?: int|null,
     *     account_entry_ids?: array<int>|null,
     *     affected_account_id?: int|null,
     *     idempotency_key?: string|null,
     *     user_id?: int|null,  // IGNORED — actor is ALWAYS Auth::id()
     * }  $params
     * @param  string  $event  One of 'refund.requested'|'refund.processed'|'refund.reversed'.
     *                         Defaults to 'refund.processed' for backward compatibility.
     *
     * @return RefundAuditLog The newly created refund_audit_logs row.
     *
     * @throws \InvalidArgumentException If $event is not one of the allowed values.
     * @throws \RuntimeException          If there is no authenticated user.
     * @throws \Throwable                 If either DB insert fails (caller's transaction rolls back).
     */
    public static function logRefund(array $params, string $event = 'refund.processed'): RefundAuditLog
    {
        // ── VALIDATION: explicit event-type allowlist per audit spec ────
        // Reversal MUST be represented as 'refund.reversed', NOT as a generic
        // 'refund.processed' — distinguishing the event is required for the
        // activity timeline to be queryable.
        $allowedEvents = ['refund.requested', 'refund.processed', 'refund.reversed'];
        if (! in_array($event, $allowedEvents, true)) {
            throw new \InvalidArgumentException(
                "RefundAuditLogger::logRefund() — invalid event '{$event}'. "
                .'Allowed values: '.implode(', ', $allowedEvents)
            );
        }

        // ── INVARIANT 1: Actor identity from Auth::id() ONLY ──
        // The $params['user_id'] field is intentionally ignored to prevent
        // any caller from spoofing the actor identity. Even if a caller
        // passes a forged user_id, it has zero effect.
        $userId = (int) (Auth::id() ?? 0);
        if ($userId <= 0) {
            throw new \RuntimeException(
                'RefundAuditLogger: no authenticated user (Auth::id() returned 0 or null). '
                .'Mandatory refund audit cannot be persisted without an authoritative actor.'
            );
        }

        $user = User::query()->find($userId);
        $userName = $user ? $user->name : ('User#'.$userId);

        // ── INVARIANT 2: BOTH inserts are MANDATORY, no swallowing ──
        // Any exception from either Eloquent call propagates to the caller.
        // The caller is inside DB::transaction → entire refund is rolled back.

        // (1) Mandatory: refund_audit_logs row
        $refundAudit = RefundAuditLog::create([
            'user_id' => $userId,
            'user_name' => $userName,
            'module' => $params['module'],
            'booking_id' => $params['booking_id'] ?? null,
            'booking_reference' => $params['booking_reference'] ?? null,
            'customer_id' => $params['customer_id'] ?? null,
            'customer_name' => $params['customer_name'] ?? null,
            'refund_amount' => (float) ($params['refund_amount'] ?? 0),
            'currency' => strtoupper((string) ($params['currency'] ?? 'EGP')),
            'paid_amount_before' => (float) ($params['paid_amount_before'] ?? 0),
            'previously_refunded' => (float) ($params['previously_refunded'] ?? 0),
            'remaining_refundable' => (float) ($params['remaining_refundable'] ?? 0),
            'reason' => $params['reason'] ?? null,
            'transaction_id' => $params['transaction_id'] ?? null,
            'account_entry_ids' => $params['account_entry_ids'] ?? null,
            'affected_account_id' => $params['affected_account_id'] ?? null,
            'idempotency_key' => $params['idempotency_key'] ?? null,
            'ip_address' => request() ? request()->ip() : null,
            'user_agent' => request() ? substr((string) request()->userAgent(), 0, 500) : null,
        ]);

        // (2) Mandatory: generic audit_logs row
        //
        // Phase 5 (B-5 fix): write BOTH the legacy polymorphic pair
        // (model_type, model_id) AND the unified pair (related_type, related_id)
        // so future cross-table audit queries can use the same WHERE clause as
        // `transactions.related_type + related_id` without translation. The
        // values are identical because the project convention is "model_type ==
        // related_type = fully-qualified Eloquent class name".
        $resolvedModelType = static::resolveModelType($params['module']);
        $resolvedBookingId = $params['booking_id'] ?? null;

        AuditLog::create([
            'user_id' => $userId,
            'action' => $event,
            'model_type' => $resolvedModelType,
            'model_id' => $resolvedBookingId,
            'related_type' => $resolvedModelType,
            'related_id' => $resolvedBookingId,
            'ip_address' => request() ? request()->ip() : null,
            'user_agent' => request() ? substr((string) request()->userAgent(), 0, 500) : null,
            'old_values' => null,
            'new_values' => [
                'refund_audit_log_id' => $refundAudit->id,
                'module' => $params['module'],
                'event' => $event,
                'refund_amount' => (float) ($params['refund_amount'] ?? 0),
                'currency' => strtoupper((string) ($params['currency'] ?? 'EGP')),
                'reason' => $params['reason'] ?? null,
            ],
            'notes' => 'Tourism refund ['.$event.'] via '
                .($params['module'] ?? 'unknown')
                .' booking #'.($params['booking_id'] ?? 'n/a'),
        ]);

        return $refundAudit;
    }

    /**
     * Resolve the morphTo model_type for the generic audit_logs table.
     */
    protected static function resolveModelType(string $module): string
    {
        return match ($module) {
            'flight' => \App\Models\Flight\FlightBooking::class,
            'hajj_umra' => \App\Models\HajjUmraBooking::class,
            'visa' => \App\Models\VisaBooking::class,
            default => 'unknown',
        };
    }
}