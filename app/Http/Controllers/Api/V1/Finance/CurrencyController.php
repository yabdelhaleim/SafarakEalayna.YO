<?php

namespace App\Http\Controllers\Api\V1\Finance;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Services\Finance\AuditService;
use App\Services\Finance\CurrencyService;
use Illuminate\Http\Request;

class CurrencyController extends Controller
{
    public function __construct(
        private CurrencyService $currencyService,
        private AuditService $auditService
    ) {}

    /**
     * تحويل عملة
     */
    public function convert(Request $request)
    {
        $validated = $request->validate([
            'amount' => 'required|numeric|min:0',
            'from_currency' => 'required|in:EGP,KWD,SAR,USD',
            'to_currency' => 'required|in:EGP,KWD,SAR,USD',
        ]);

        $result = $this->currencyService->convert(
            $validated['amount'],
            $validated['from_currency'],
            $validated['to_currency']
        );

        return ApiResponse::success('تم التحويل بنجاح', $result);
    }

    /**
     * إدخال سعر صرف
     */
    public function setRate(Request $request)
    {
        $validated = $request->validate([
            'from_currency' => 'required|in:EGP,KWD,SAR,USD',
            'to_currency' => 'required|in:EGP,KWD,SAR,USD',
            'rate' => 'required|numeric|min:0',
            'effective_date' => 'nullable|date',
            'is_active' => 'nullable|boolean',
        ]);

        $rate = $this->currencyService->setExchangeRate($validated);

        return ApiResponse::success('تم إدخال سعر الصرف بنجاح', $rate);
    }

    /**
     * عرض كل الأسعار النشطة
     */
    public function getActiveRates()
    {
        $rates = $this->currencyService->getActiveRates();

        return ApiResponse::success('أسعار الصرف النشطة', $rates);
    }
}
