<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AuthenticateWithApiToken
{
    public function handle(Request $request, Closure $next, ...$guards)
    {
        // 1. Handle token passed in query parameter (SSO from Vue SPA)
        if ($request->has('token')) {
            $token = $request->query('token');
            \Log::info('SSO: Token received: ' . substr($token, 0, 10) . '...');
            
            $user = \Laravel\Sanctum\PersonalAccessToken::findToken($token)?->tokenable;

            if ($user) {
                \Log::info('SSO: User found: ' . $user->email . ' (Role: ' . $user->role . ', Active: ' . ($user->is_active ? 'Yes' : 'No') . ')');
                if (in_array($user->role, ['admin', 'owner'], true)) {
                    if ($user->is_active) {
                        Auth::login($user); // Reverted to avoid remember_token DB error
                        \Log::info('SSO: Auth::login called successfully for user: ' . $user->email);
                    } else {
                        \Log::warning('SSO: User is inactive.');
                    }
                } else {
                    \Log::warning('SSO: User role not authorized for admin: ' . $user->role);
                }
            } else {
                \Log::error('SSO: No user found for token.');
            }

            // If we redirect here, the session might be dropped on some browsers due to SameSite policies on new tabs.
            // Let it fall through so the request continues and the user gets the admin panel directly.
            // But we need to make sure we don't return early.
        }

        // 2. Check if user is already authenticated via session
        if (Auth::check()) {
            return $next($request);
        }

        // 3. Check for API token in Authorization header
        $authHeader = $request->header('Authorization');
        if ($authHeader && str_starts_with($authHeader, 'Bearer ')) {
            $token = substr($authHeader, 7);

            // Find user by personal access token
            $user = \Laravel\Sanctum\PersonalAccessToken::findToken($token)?->tokenable;

            if ($user && $user->is_active) {
                // Log in the user via session
                Auth::login($user);
            }
        }

        return $next($request);
    }
}