<?php

namespace App\Support\Finance;

/**
 * Reusable profit-column guard for Eloquent models whose `profit` field is a
 * derived financial figure (selling − purchase, × qty, etc.) and must only be
 * written through the canonical service paths.
 *
 * Background:
 *   Several booking/transaction models carry a denormalized `profit` column
 *   for fast display (dashboards, Filament tables, exports). That column is
 *   a *cache* of what the GL (`transactions` + `account_entries`) already
 *   knows with full fidelity. To prevent drift, writes to `profit` must go
 *   through the model's owning service (which also posts the matching GL
 *   entry). Direct mutation from Filament resources, controllers, seeders,
 *   tinker, or stray `->save()` calls would let `profit` diverge from the
 *   ledger — the exact failure mode this guard exists to prevent.
 *
 *   At the same time, the *proper* "recompute profit on price change" flow
 *   is a first-class operation in each booking service. It must be able to
 *   write `profit` from inside its own service without tripping the guard.
 *
 * This trait provides the depth-counter pattern (same shape as
 * `LedgerBalanceMutationGuard` and `ModelDeletionGuard`) so that:
 *
 *   1. Each model that composes `ModelProfitMutationGuard` gets its OWN
 *      `$profitDepth` static via PHP's per-class trait-static-variable
 *      behavior. Two different models using this trait do NOT share their
 *      counters — so `FlightBooking::run(...)` and `BusBooking::run(...)`
 *      can never cross-contaminate.
 *
 *   2. Each model's `saving` observer checks ITS OWN `isAllowed()` — so
 *      the gate is per-model and self-contained. No global lock.
 *
 *   3. The canonical service path wraps its profit-writing operations
 *      inside `ModelClass::runProfitMutation(...)` to flip the gate briefly open.
 *
 * Usage:
 *
 *   class FlightBooking extends Model {
 *       use SoftDeletes, ModelDeletionGuard, ModelProfitMutationGuard;
 *
 *       protected static function booted(): void {
 *           static::saving(function (FlightBooking $booking): void {
 *               if (! $booking->isDirty('profit')) return;
 *               if (LedgerBalanceMutationGuard::isAllowed()) return;
 *               if (app()->runningUnitTests()) return;
 *               if (static::isProfitMutationAllowed()) return;
 *               throw new \RuntimeException(
 *                   'لا يمكن تعديل عمود profit في حجز الطيران مباشرةً. '
 *                   .'استخدم FlightBookingService::createBooking/updateBooking/updatePrices.'
 *               );
 *           });
 *       }
 *   }
 *
 *   FlightBookingService::updatePrices(...) {
 *       FlightBooking::runProfitMutation(function () use ($booking, $sellingPrice, $purchasePrice) {
 *           $profit = $sellingPrice - $purchasePrice;
 *           $booking->update(['profit' => $profit, ...]);
 *       });
 *   }
 *
 * Note on method naming: the methods are explicitly named
 * `runProfitMutation()` and `isProfitMutationAllowed()` (not `run` /
 * `isAllowed`) so that models composing BOTH this trait and
 * `ModelDeletionGuard` do NOT collide on the shared method names. Each
 * gate still uses its own per-class depth counter.
 *
 * Origin: introduced during the Phase 5 profit-column hardening pass. Mirrors
 * `App\Support\Finance\ModelDeletionGuard` (the established pattern for
 * deletion-side gates).
 */
trait ModelProfitMutationGuard
{
    /**
     * Per-class depth counter. Each class that uses this trait gets its
     * own static (PHP trait behavior) — no global lock.
     */
    private static int $profitDepth = 0;

    /**
     * Run $callback with the model's profit gate flipped open.
     *
     * Nested calls increment and decrement the counter so callbacks can
     * safely wrap writes that fan out into other writes of the same model.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function runProfitMutation(callable $callback): mixed
    {
        ++self::$profitDepth;
        try {
            return $callback();
        } finally {
            --self::$profitDepth;
        }
    }

    /**
     * Returns true iff we are currently inside `static::runProfitMutation(...)`.
     *
     * Models' `saving` observers call this to allow the canonical service
     * to write the `profit` column without tripping the guard.
     */
    public static function isProfitMutationAllowed(): bool
    {
        return self::$profitDepth > 0;
    }
}