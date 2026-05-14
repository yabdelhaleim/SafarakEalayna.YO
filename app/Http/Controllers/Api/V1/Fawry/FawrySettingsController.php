<?php

namespace App\Http\Controllers\Api\V1\Fawry;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Fawry\FawryOperationType;
use App\Models\Fawry\FawryPaymentMethod;
use App\Models\Fawry\FawryCurrency;
use Illuminate\Http\JsonResponse;

class FawrySettingsController extends Controller
{
    /**
     * الحصول على أنواع عمليات فوري النشطة
     */
    public function operationTypes(): JsonResponse
    {
        $types = FawryOperationType::active()->get()->map(function ($type) {
            return [
                'id' => $type->id,
                'value' => $type->code,
                'label' => $type->name_ar,
                'labelEn' => $type->name_en,
                'color' => $type->color,
                'icon' => $type->icon,
                'description' => $type->description_ar,
                'descriptionEn' => $type->description_en,
            ];
        });

        return ApiResponse::success('تم جلب أنواع عمليات فوري بنجاح', $types);
    }

    /**
     * الحصول على طرق دفع فوري النشطة مع تفاصيلها
     */
    public function paymentMethods(): JsonResponse
    {
        $methods = FawryPaymentMethod::active()->with('defaultAccount')->get()->map(function ($method) {
            return [
                'id' => $method->id,
                'value' => $method->code,
                'label' => $method->name_ar,
                'labelEn' => $method->name_en,
                'color' => $method->color,
                'icon' => $method->icon,
                'description' => $method->description_ar,
                'descriptionEn' => $method->description_en,
                'providerName' => $method->provider_name,
                'bankName' => $method->bank_name,
                'branchName' => $method->branch_name,
                'accountNumber' => $method->account_number,
                'phoneNumber' => $method->phone_number,
                'metadata' => $method->metadata,
                'defaultAccountId' => $method->default_account_id,
                'defaultAccountName' => $method->defaultAccount?->name,
                'fullDetails' => $method->full_details,
            ];
        });

        return ApiResponse::success('تم جلب طرق دفع فوري بنجاح', $methods);
    }

    /**
     * الحصول على عملات فوري النشطة
     */
    public function currencies(): JsonResponse
    {
        $currencies = FawryCurrency::active()->with('currency')->get()->map(function ($fawryCurrency) {
            $currency = $fawryCurrency->currency;

            return [
                'id' => $fawryCurrency->id,
                'currencyId' => $fawryCurrency->currency_id,
                'code' => $currency?->code,
                'name' => $currency?->name_ar,
                'nameEn' => $currency?->name_en,
                'symbol' => $currency?->symbol,
                'exchangeRate' => $fawryCurrency->exchange_rate,
                'minAmount' => $fawryCurrency->min_amount,
                'maxAmount' => $fawryCurrency->max_amount,
                'feePercent' => $fawryCurrency->fee_percent,
                'fixedFee' => $fawryCurrency->fixed_fee,
            ];
        });

        return ApiResponse::success('تم جلب عملات فوري بنجاح', $currencies);
    }

    /**
     * الحصول على جميع الإعدادات في استدعاء واحد
     */
    public function all(): JsonResponse
    {
        $operationTypes = FawryOperationType::active()->get()->map(function ($type) {
            return [
                'value' => $type->code,
                'label' => $type->name_ar,
                'labelEn' => $type->name_en,
                'color' => $type->color,
                'icon' => $type->icon,
            ];
        });

        $paymentMethods = FawryPaymentMethod::active()->with('defaultAccount')->get()->map(function ($method) {
            return [
                'value' => $method->code,
                'label' => $method->name_ar,
                'labelEn' => $method->name_en,
                'color' => $method->color,
                'icon' => $method->icon,
                'providerName' => $method->provider_name,
                'bankName' => $method->bank_name,
                'accountNumber' => $method->account_number,
                'phoneNumber' => $method->phone_number,
                'defaultAccountId' => $method->default_account_id,
                'defaultAccountName' => $method->defaultAccount?->name,
                'fullDetails' => $method->full_details,
            ];
        });

        $currencies = FawryCurrency::active()->with('currency')->get()->map(function ($fawryCurrency) {
            $currency = $fawryCurrency->currency;

            return [
                'id' => $fawryCurrency->id,
                'currencyId' => $fawryCurrency->currency_id,
                'value' => $currency?->code,
                'label' => $currency?->name_ar,
                'labelEn' => $currency?->name_en,
                'symbol' => $currency?->symbol,
                'exchangeRate' => $fawryCurrency->exchange_rate,
                'minAmount' => $fawryCurrency->min_amount,
                'maxAmount' => $fawryCurrency->max_amount,
            ];
        });

        return ApiResponse::success('تم جلب إعدادات فوري بنجاح', [
            'operationTypes' => $operationTypes,
            'paymentMethods' => $paymentMethods,
            'currencies' => $currencies,
        ]);
    }
}
