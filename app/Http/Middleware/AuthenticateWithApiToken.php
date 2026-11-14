<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

class AuthenticateWithApiToken
{
    public function handle(Request $request, Closure $next, ...$guards)
    {
        // ─────────────────────────────────────────────────────────────────
        // 1. SSO: Token passed via query parameter (Vue SPA → Filament tab)
        //
        // SEC-01 / SEC-03 / SEC-04:
        //  - Token must have been issued as a one-time SSO nonce
        //    (stored in cache by /sso-login route).
        //  - Consumed immediately after first use to prevent replay attacks.
        //  - Session is REGENERATED after login to prevent session fixation.
        //  - Request continues WITHOUT the token visible in the URL
        //    (strip it via redirect below).
        // ─────────────────────────────────────────────────────────────────
        if ($request->has('token')) {
            $rawToken = $request->query('token');
            $cacheKey = 'sso_nonce:' . hash('sha256', $rawToken);

            // Only honour tokens that were explicitly registered as SSO nonces.
            // Regular API Bearer tokens cannot be replayed via the URL.
            if (Cache::has($cacheKey)) {
                Cache::forget($cacheKey); // one-time use — consume immediately

                $user = \Laravel\Sanctum\PersonalAccessToken::findToken($rawToken)?->tokenable;

                if ($user && $user->is_active && in_array($user->role, ['admin', 'owner'], true)) {
                    Auth::login($user);
                    // SEC-04: regenerate session ID to prevent session fixation
                    $request->session()->regenerate();
                    \Log::info('SSO: User authenticated and session regenerated', [
                        'user_id' => $user->id,
                        'email'   => $user->email,
                        'ip'      => $request->ip(),
                    ]);

                    // SEC-09: redirect to clean URL (no token in address bar / logs)
                    $cleanUrl = $request->url(); // strips query string
                    return redirect($cleanUrl);
                }

                \Log::warning('SSO: Token nonce valid but user check failed', [
                    'ip' => $request->ip(),
                ]);
            } else {
                // Token not registered as an SSO nonce — possible replay attack or
                // direct URL manipulation attempt. Log and silently ignore.
                \Log::warning('SSO: Unregistered or replayed token in URL — rejected', [
                    'ip'            => $request->ip(),
                    'token_prefix'  => substr($rawToken, 0, 8) . '...',
                ]);
            }
        }

        // ─────────────────────────────────────────────────────────────────
        // 2. Already authenticated via session — fast path
        // ─────────────────────────────────────────────────────────────────
        if (Auth::check()) {
            return $next($request);
        }

        // ─────────────────────────────────────────────────────────────────
        // 3. Bearer token in Authorization header (Filament direct API calls)
        // ─────────────────────────────────────────────────────────────────
        $authHeader = $request->header('Authorization');
        if ($authHeader && str_starts_with($authHeader, 'Bearer ')) {
            $token = substr($authHeader, 7);
            $user  = \Laravel\Sanctum\PersonalAccessToken::findToken($token)?->tokenable;

            if ($user && $user->is_active) {
                Auth::login($user);
                // Regenerate here too if the session was just started
                if (! $request->session()->has('_token')) {
                    $request->session()->regenerate();
                }
            }
        }

        return $next($request);
    }
}
