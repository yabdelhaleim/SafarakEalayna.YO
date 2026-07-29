<?php

namespace Tests\Feature\Bus;

use App\Support\Finance\DeadlockRetry;
use Illuminate\Support\Facades\Log;
use PDOException;
use RuntimeException;

/**
 * Unit tests for the DeadlockRetry trait.
 * ──────────────────────────────────────────
 *
 * The trait wraps a callback in a loop that catches MySQL's transient
 * conflict errors (code 1020 "Record has changed" / 1213 "Deadlock") and
 * retries up to N times with linear backoff (50ms, 100ms, 150ms).
 *
 * Currently used in:
 *   - app/Services/Flight/FlightCarrierRechargeService.php
 *   - app/Services/Flight/FlightSystemRechargeService.php
 *   - app/Services/Flight/RefundService.php
 *
 * NOT YET used in Bus services (gap). These tests pin the trait's
 * behavior so it can be safely composed into Bus services in a future
 * PR — and so a regression in the trait itself is caught.
 *
 * We don't simulate real MySQL deadlocks (in-memory SQLite won't produce
 * them). Instead we directly throw `PDOException` with the messages the
 * trait's string-matching logic checks for. The trait doesn't inspect
 * the code attribute — only the message text — so this is sufficient.
 */
class BusDeadlockRetryTest extends BusTestCase
{
    /**
     * Minimal harness that uses the trait and exposes the protected
     * method via a public passthrough. Lets us invoke it directly
     * from the test methods.
     */
    private function harness(): object
    {
        return new class
        {
            use DeadlockRetry;

            public function run(callable $cb, array $context = [], int $maxAttempts = 3): mixed
            {
                return $this->withDeadlockRetry($cb, $context, $maxAttempts);
            }

            /**
             * Expose attempts count to tests for assertion. We do this
             * via a shared counter on the harness instance.
             */
            public int $attempts = 0;

            public function runCounting(callable $cb, array $context = [], int $maxAttempts = 3): mixed
            {
                $this->attempts = 0;
                $wrapped = function () use ($cb) {
                    $this->attempts++;
                    return $cb();
                };
                return $this->withDeadlockRetry($wrapped, $context, $maxAttempts);
            }
        };
    }

    private function deadlockException(string $msg = 'SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction'): PDOException
    {
        return new PDOException($msg);
    }

    private function snapshotException(): PDOException
    {
        return new PDOException('SQLSTATE[HY000]: General error: 1020 Record has changed since last read');
    }

    // ─────────────────────────────────────────────────────────────────────
    // DR1 — Retries on 1213 deadlock and eventually succeeds
    // ─────────────────────────────────────────────────────────────────────

    public function test_retries_on_1213_deadlock_and_eventually_succeeds(): void
    {
        $h = $this->harness();
        $callCount = 0;

        // First 2 calls throw 1213 deadlock, 3rd call succeeds.
        $result = $h->runCounting(function () use (&$callCount) {
            $callCount++;
            if ($callCount <= 2) {
                throw $this->deadlockException();
            }
            return 'success';
        }, context: ['op' => 'unit'], maxAttempts: 3);

        $this->assertEquals('success', $result, 'Should return success on 3rd attempt');
        $this->assertEquals(3, $callCount, 'Should have been called exactly 3 times');
        $this->assertEquals(3, $h->attempts, 'Harness attempts counter matches');
    }

    // ─────────────────────────────────────────────────────────────────────
    // DR2 — Retries on 1020 snapshot conflict
    // ─────────────────────────────────────────────────────────────────────

    public function test_retries_on_1020_snapshot_conflict(): void
    {
        $h = $this->harness();
        $callCount = 0;

        $result = $h->runCounting(function () use (&$callCount) {
            $callCount++;
            if ($callCount <= 2) {
                throw $this->snapshotException();
            }
            return 'recovered';
        }, context: ['op' => 'snapshot-test'], maxAttempts: 3);

        $this->assertEquals('recovered', $result);
        $this->assertEquals(3, $callCount, 'Should have been called exactly 3 times');
    }

    // ─────────────────────────────────────────────────────────────────────
    // DR3 — Throws after max attempts exhausted
    // ─────────────────────────────────────────────────────────────────────

    public function test_throws_after_max_attempts_exhausted(): void
    {
        $h = $this->harness();
        $callCount = 0;

        try {
            $h->runCounting(function () use (&$callCount) {
                $callCount++;
                throw $this->deadlockException();
            }, context: ['op' => 'always-fails'], maxAttempts: 3);
            $this->fail('Expected PDOException after exhausting attempts');
        } catch (PDOException $e) {
            $this->assertEquals(3, $callCount, 'Should have been called exactly 3 times (maxAttempts)');
            $this->assertStringContainsString('1213', $e->getMessage(), 'Original PDOException should bubble up');
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // DR4 — Does NOT retry non-retryable PDOException
    // ─────────────────────────────────────────────────────────────────────

    public function test_does_not_retry_non_retryable_pdo_exception(): void
    {
        $h = $this->harness();
        $callCount = 0;

        try {
            $h->runCounting(function () use (&$callCount) {
                $callCount++;
                // PDOException without "1213", "1020", "Deadlock", or "Record has changed"
                throw new PDOException('SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry');
            }, context: ['op' => 'duplicate-key'], maxAttempts: 3);
            $this->fail('Expected PDOException to bubble up immediately');
        } catch (PDOException $e) {
            $this->assertEquals(1, $callCount, 'Should have been called exactly 1 time (no retry for non-deadlock)');
            $this->assertStringContainsString('Duplicate entry', $e->getMessage(), 'Original exception preserved');
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // DR5 — Does NOT retry non-PDOException
    // ─────────────────────────────────────────────────────────────────────

    public function test_does_not_retry_non_pdo_exception(): void
    {
        $h = $this->harness();
        $callCount = 0;

        try {
            $h->runCounting(function () use (&$callCount) {
                $callCount++;
                throw new RuntimeException('Some app-level error');
            }, context: ['op' => 'app-error'], maxAttempts: 3);
            $this->fail('Expected RuntimeException to bubble up immediately');
        } catch (RuntimeException $e) {
            $this->assertEquals(1, $callCount, 'Should have been called exactly 1 time (no retry for non-PDO)');
            $this->assertEquals('Some app-level error', $e->getMessage());
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // DR6 — Logs warning on each retry with full context
    // ─────────────────────────────────────────────────────────────────────

    public function test_logs_warning_on_each_retry_with_context(): void
    {
        // The trait logs a warning BEFORE each retry (not on the final
        // failed attempt). With maxAttempts=3 and 2 deadlocks then success,
        // the trait should log warnings on attempts 1 and 2 (both retried
        // to attempt 2 and 3). We assert the warning was emitted with
        // the expected keys.
        Log::shouldReceive('warning')
            ->twice()
            ->withArgs(function (string $message, array $context) {
                // Message format: 'Deadlock/snapshot conflict detected, retrying'
                if ($message !== 'Deadlock/snapshot conflict detected, retrying') {
                    return false;
                }
                // Required context keys
                $required = ['attempt', 'max_attempts', 'context', 'error_code', 'error_excerpt'];
                foreach ($required as $k) {
                    if (! array_key_exists($k, $context)) {
                        return false;
                    }
                }
                // attempt must be 1 or 2 (the first two failed attempts)
                if (! in_array($context['attempt'], [1, 2], true)) {
                    return false;
                }
                // max_attempts must equal 3
                if ($context['max_attempts'] !== 3) {
                    return false;
                }
                // context must contain our op key
                if (($context['context']['op'] ?? null) !== 'log-test') {
                    return false;
                }
                // error_code must be a known code label
                if (! in_array($context['error_code'], ['1020-snapshot', '1213-deadlock'], true)) {
                    return false;
                }
                return true;
            });

        $h = $this->harness();
        $callCount = 0;
        $result = $h->runCounting(function () use (&$callCount) {
            $callCount++;
            if ($callCount <= 2) {
                throw $this->deadlockException();
            }
            return 'recovered';
        }, context: ['op' => 'log-test'], maxAttempts: 3);

        $this->assertEquals('recovered', $result);
        $this->assertEquals(3, $callCount);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Bonus — Confirms retry-amount tracking with custom maxAttempts=5
    // ─────────────────────────────────────────────────────────────────────

    public function test_custom_max_attempts_is_respected(): void
    {
        $h = $this->harness();
        $callCount = 0;

        // maxAttempts=5: first 4 calls throw, 5th succeeds
        $result = $h->runCounting(function () use (&$callCount) {
            $callCount++;
            if ($callCount <= 4) {
                throw $this->deadlockException();
            }
            return 'finally';
        }, context: ['op' => 'custom-attempts'], maxAttempts: 5);

        $this->assertEquals('finally', $result);
        $this->assertEquals(5, $callCount, 'Should retry up to maxAttempts=5');
    }
}