<?php

namespace App\Http\Middleware;

use App\Support\Finance\PostingContext;
use App\Support\Finance\PostingContextRegistry;
use Closure;
use Illuminate\Http\Request;

class CaptureFinancialPostingContext
{
    public function handle(Request $request, Closure $next): mixed
    {
        if (! config('accounting.audit.capture_http', true)) {
            return $next($request);
        }

        $registry = app(PostingContextRegistry::class);
        $registry->set(PostingContext::fromHttpRequest($request));

        try {
            return $next($request);
        } finally {
            $registry->clear();
        }
    }
}
