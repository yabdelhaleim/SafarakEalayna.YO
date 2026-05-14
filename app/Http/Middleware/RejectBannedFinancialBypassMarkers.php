<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * يمنع علامات/رؤوس تجريبية قد تُستخدم كمحاولة تجاوز مسار الدفتر (فشل صريح).
 */
class RejectBannedFinancialBypassMarkers
{
    public function handle(Request $request, Closure $next): mixed
    {
        if (! config('accounting.middleware.reject_bypass_markers', true)) {
            return $next($request);
        }

        if ($request->query->has('direct_financial_write') || $request->request->has('direct_financial_write')) {
            abort(403, 'تم رفض طلب يحتوي على وسيط تجاوز محظور (direct_financial_write).');
        }

        if ($request->header('X-Allow-Direct-Ledger')) {
            abort(403, 'تم رفض رأس HTTP محظور (X-Allow-Direct-Ledger).');
        }

        return $next($request);
    }
}
