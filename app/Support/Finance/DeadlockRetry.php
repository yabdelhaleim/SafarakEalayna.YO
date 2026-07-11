<?php

namespace App\Support\Finance;

use Illuminate\Support\Facades\Log;

/**
 * Retry-on-transient-conflict trait for financial DB operations.
 *
 * Wraps a callback in a loop that catches `PDOException` for the two
 * retryable MySQL errors:
 *   - 1020 — "Record has changed since last read" (snapshot conflict under
 *     READ-COMMITTED isolation when another tx commits between our SELECT
 *     and our UPDATE).
 *   - 1213 — "Deadlock found" (two transactions holding locks in opposite
 *     orders). With our ID-ascending `lockForUpdate` order this is
 *     extremely unlikely, but still possible.
 *
 * Behavior:
 *   - Up to 3 attempts by default (configurable).
 *   - Linear backoff: 50ms, 100ms, 150ms between retries.
 *   - On each retry: Log::warning with context + truncated error excerpt.
 *   - If the error is NOT one of the two retryable codes, OR we exhausted
 *     attempts: re-throw the original `PDOException` (caller decides).
 *
 * Usage:
 *
 *   class RefundService {
 *       use DeadlockRetry;
 *
 *       public function process(...) {
 *           return $this->withDeadlockRetry(
 *               fn () => DB::transaction(fn () => $this->doWork()),
 *               context: ['refund_id' => $id],
 *           );
 *       }
 *   }
 *
 * Original logic lifted from `FlightCarrierRechargeService::rechargeFromAccount()`
 * so the same retry semantics apply wherever this trait is composed in.
 */
trait DeadlockRetry
{
    /**
     * Run $callback with automatic retry on transient MySQL conflicts.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @param  array<string, mixed>  $context  free-form metadata logged on each retry
     * @param  int  $maxAttempts  total attempts (default 3)
     * @return T
     *
     * @throws \PDOException when non-retryable OR attempts exhausted
     */
    protected function withDeadlockRetry(
        callable $callback,
        array $context = [],
        int $maxAttempts = 3,
    ): mixed {
        $attempt = 0;

        while (true) {
            $attempt++;
            try {
                return $callback();
            } catch (\PDOException $e) {
                $msg = $e->getMessage();

                // Retryable:
                //   1020 — Record has changed since last read (snapshot conflict)
                //   1213 — Deadlock found (extremely unlikely with our ID-ascending locks)
                $isRetryable = str_contains($msg, '1020')
                    || str_contains($msg, 'Record has changed')
                    || str_contains($msg, '1213')
                    || str_contains($msg, 'Deadlock');

                if ($isRetryable && $attempt < $maxAttempts) {
                    Log::warning('Deadlock/snapshot conflict detected, retrying', [
                        'attempt' => $attempt,
                        'max_attempts' => $maxAttempts,
                        'context' => $context,
                        'error_code' => str_contains($msg, '1020') ? '1020-snapshot' : '1213-deadlock',
                        'error_excerpt' => mb_substr($msg, 0, 200),
                    ]);
                    // backoff: 50ms, 100ms, 150ms between retries
                    usleep(50000 * $attempt);
                    continue;
                }

                throw $e;
            }
        }
    }
}