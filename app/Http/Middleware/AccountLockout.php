<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * SEC-10: Account-level brute-force lockout.
 *
 * Works in ADDITION to the IP-level `throttle:auth` rate limiter.
 * After LOGIN_MAX_ATTEMPTS failed login attempts for a specific email,
 * the account is locked for LOGIN_LOCKOUT_MINUTES regardless of IP.
 *
 * This protects against:
 *  - Distributed brute-force (many IPs, same target account)
 *  - Credential-stuffing attacks where the attacker knows the email
 *
 * Usage: apply as middleware on the POST /api/v1/auth/login route,
 *        OR call AccountLockout::increment() / ::clear() from AuthController.
 */
class AccountLockout
{
    private const MAX_ATTEMPTS      = 10;   // failed logins before lockout
    private const LOCKOUT_MINUTES   = 15;   // lockout duration
    private const ATTEMPT_TTL_MINS  = 30;   // rolling window for counting failures

    public static function cacheKey(string $email): string
    {
        return 'login_failures:' . hash('sha256', strtolower(trim($email)));
    }

    /**
     * Increment the failure counter for the given email.
     * Called from AuthController on a failed login attempt.
     */
    public static function increment(string $email): void
    {
        $key      = self::cacheKey($email);
        $attempts = (int) Cache::get($key, 0) + 1;
        Cache::put($key, $attempts, now()->addMinutes(self::ATTEMPT_TTL_MINS));

        if ($attempts >= self::MAX_ATTEMPTS) {
            // Write a separate lockout flag with its own TTL so the attempt
            // counter can keep incrementing for audit purposes.
            Cache::put(
                'login_locked:' . hash('sha256', strtolower(trim($email))),
                true,
                now()->addMinutes(self::LOCKOUT_MINUTES)
            );

            \Log::warning('Account lockout triggered', [
                'email'    => $email,
                'attempts' => $attempts,
                'locked_until' => now()->addMinutes(self::LOCKOUT_MINUTES)->toIso8601String(),
            ]);
        }
    }

    /**
     * Clear the failure counter for the given email (called on successful login).
     */
    public static function clear(string $email): void
    {
        $hash = hash('sha256', strtolower(trim($email)));
        Cache::forget('login_failures:' . $hash);
        Cache::forget('login_locked:' . $hash);
    }

    /**
     * Check whether the account is currently locked.
     */
    public static function isLocked(string $email): bool
    {
        return (bool) Cache::get('login_locked:' . hash('sha256', strtolower(trim($email))));
    }

    /**
     * Middleware handle — rejects the request early if the target account
     * is locked. Avoids even running the password-check logic.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $email = (string) ($request->input('email') ?? '');

        if ($email !== '' && self::isLocked($email)) {
            return response()->json([
                'success' => false,
                'message' => 'الحساب مقفل مؤقتاً بسبب محاولات دخول متكررة. حاول مرة أخرى بعد ' . self::LOCKOUT_MINUTES . ' دقيقة.',
                'data'    => null,
            ], Response::HTTP_TOO_MANY_REQUESTS);
        }

        return $next($request);
    }
}
