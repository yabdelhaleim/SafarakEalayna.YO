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

    public function index(Request $request)
    {
        $rates = \App\Models\ExchangeRate::orderBy('effective_date', 'desc')->get();
        return ApiResponse::success('قائمة أسعار الصرف', $rates);
    }

    public function store(Request $request)
    {
        return $this->setRate($request);
    }

    public function show(\App\Models\ExchangeRate $currency)
    {
        return ApiResponse::success('تفاصيل سعر الصرف', $currency);
    }

    public function update(Request $request, \App\Models\ExchangeRate $currency)
    {
        $validated = $request->validate([
            'rate' => 'sometimes|required|numeric|min:0',
            'effective_date' => 'nullable|date',
            'is_active' => 'boolean',
        ]);

        $currency->update($validated);

        return ApiResponse::success('تم تحديث سعر الصرف بنجاح', $currency);
    }

    public function destroy(\App\Models\ExchangeRate $currency)
    {
        $currency->delete();

        return ApiResponse::success('تم حذف سعر الصرف بنجاح');
    }
}
