<?php

namespace App\Support\Finance;

/**
 * Reusable deletion guard for Eloquent models that need controlled soft-delete entry points.
 *
 * Background:
 *   Some models (e.g. FlightBooking, HajjUmraBooking) carry financial
 *   implications when deleted and therefore block raw `->delete()` calls
 *   from outside their canonical reversal service — to prevent Filament
 *   `DeleteAction`, accidental API hits, or tinker mistakes from silently
 *   corrupting the ledger.
 *
 *   At the same time, the *proper* "soft delete + full financial reversal"
 *   operation is a first-class action (`FlightBookingService::deleteBookingWithReversal`
 *   is the canonical Flight example). It must be able to call `$model->delete()`
 *   from inside its own service without tripping the model's `deleting` guard.
 *
 * This trait provides the depth-counter pattern (same shape as
 * `LedgerBalanceMutationGuard`) so that:
 *
 *   1. Each model that composes `ModelDeletionGuard` gets its OWN `$depth`
 *      static via PHP's per-class trait-static-variable behavior. Two
 *      different models using this trait do NOT share their counters — so
 *      `FlightBooking::run(...)` and `HajjUmraBooking::run(...)` can never
 *      cross-contaminate.
 *
 *   2. Each model's `deleting` observer checks ITS OWN `isAllowed()` —
 *      so the gate is per-model and self-contained. No global lock.
 *
 *   3. The canonical reversal service wraps the entire `$model->delete()`
 *      call inside `ModelClass::run(...)` to flip the gate briefly open.
 *
 * Usage:
 *
 *   class HajjUmraBooking extends Model {
 *       use SoftDeletes, ModelDeletionGuard;
 *
 *       protected static function booted(): void {
 *           static::deleting(function (HajjUmraBooking $booking) {
 *               if (!app()->runningUnitTests() && !static::isAllowed()) {
 *                   throw new \RuntimeException(
 *                       'لا يمكن حذف حجز الحج والعمرة برمجياً. '
 *                       .'استخدم HajjUmraBookingService::deleteBookingWithReversal().'
 *                   );
 *               }
 *           });
 *       }
 *   }
 *
 *   HajjUmraBookingService::deleteBookingWithReversal(...) {
 *       return HajjUmraBooking::run(function () use ($bookingId, $userId) {
 *           // ... reverse + soft-delete ...
 *           $booking->delete();   // ← allowed because gate is open
 *       });
 *   }
 *
 * Origin: extracted from `App\Support\Finance\FlightBookingDeletionGuard`
 * (now removed). The trait replaces that class — call sites in
 * `FlightBookingService::deleteBookingWithReversal` were migrated from
 * `\App\Support\Finance\FlightBookingDeletionGuard::run(...)` to
 * `FlightBooking::run(...)`.
 */
trait ModelDeletionGuard
{
    /**
     * Per-class depth counter. Each class that uses this trait gets its
     * own static (PHP trait behavior) — no global lock.
     */
    private static int $deletionDepth = 0;

    /**
     * Run $callback with the model's deletion gate flipped open.
     *
     * Nested calls increment and decrement the counter so callbacks can
     * safely wrap deletes that fan out into other deletes of the same
     * model.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function run(callable $callback): mixed
    {
        ++self::$deletionDepth;
        try {
            return $callback();
        } finally {
            --self::$deletionDepth;
        }
    }

    /**
     * Returns true iff we are currently inside `static::run(...)`.
     *
     * Models' `deleting` observers call this to allow soft-delete during
     * the canonical reversal flow.
     */
    public static function isAllowed(): bool
    {
        return self::$deletionDepth > 0;
    }
}
