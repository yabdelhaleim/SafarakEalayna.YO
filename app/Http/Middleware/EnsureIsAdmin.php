<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureIsAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user() || ! in_array($request->user()->role, ['admin', 'owner'], true)) {
            return response()->json([
                'success' => false,
                'message' => 'غير مصرح لك بالوصول',
                'data' => null,
            ], Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}
