<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AuthenticateWithApiToken
{
    public function handle(Request $request, Closure $next, ...$guards)
    {
        // Check if user is already authenticated via session
        if (Auth::check()) {
            return $next($request);
        }

        // Check for API token in Authorization header
        $authHeader = $request->header('Authorization');
        if ($authHeader && str_starts_with($authHeader, 'Bearer ')) {
            $token = substr($authHeader, 7);

            // Find user by personal access token
            $user = \Laravel\Sanctum\PersonalAccessToken::findToken($token)?->tokenable;

            if ($user) {
                // Log in the user via session
                Auth::login($user);
            }
        }

        return $next($request);
    }
}