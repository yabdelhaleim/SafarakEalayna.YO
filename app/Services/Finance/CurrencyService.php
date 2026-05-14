<?php

namespace App\Services\Finance;

use App\Models\AuditLog;
use App\Models\ExchangeRate;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class CurrencyService
{
    /**
     * تحويل مبلغ من عملة لأخرى
     */
    public function convert(float $amount, string $fromCurrency, string $toCurrency, ?Carbon $date = null): array
    {
        if ($fromCurrency === $toCurrency) {
            return [
                'from_amount' => $amount,
                'from_currency' => $fromCurrency,
                'to_amount' => $amount,
                'to_currency' => $toCurrency,
                'rate' => 1.0,
            ];
        }

        $date = $date ?? now();
        $rate = ExchangeRate::where('from_currency', $fromCurrency)
            ->where('to_currency', $toCurrency)
            ->where('effective_date', '<=', $date)
            ->where('is_active', true)
            ->orderBy('effective_date', 'desc')
            ->first();

        if (!$rate) {
            throw new \Exception("لا يوجد سعر صرف متاح من {$fromCurrency} إلى {$toCurrency} في تاريخ {$date}");
        }

        $convertedAmount = $amount * $rate->rate;

        return [
            'from_amount' => $amount,
            'from_currency' => $fromCurrency,
            'to_amount' => $convertedAmount,
            'to_currency' => $toCurrency,
            'rate' => $rate->rate,
            'rate_date' => $rate->effective_date,
        ];
    }

    /**
     * إدخال سعر صرف جديد
     */
    public function setExchangeRate(array $data): ExchangeRate
    {
        return DB::transaction(function () use ($data) {
            $rate = ExchangeRate::create([
                'from_currency' => $data['from_currency'],
                'to_currency' => $data['to_currency'],
                'rate' => $data['rate'],
                'effective_date' => $data['effective_date'] ?? now(),
                'is_active' => $data['is_active'] ?? true,
                'created_by' => Auth::id(),
            ]);

            // Audit Log
            AuditLog::create([
                'user_id' => Auth::id(),
                'action' => 'create_exchange_rate',
                'model_type' => ExchangeRate::class,
                'model_id' => $rate->id,
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent(),
                'new_values' => $rate->toArray(),
            ]);

            return $rate;
        });
    }

    /**
     * الحصول على كل أسعار الصرف النشطة
     */
    public function getActiveRates(): array
    {
        $rates = ExchangeRate::where('is_active', true)
            ->orderBy('effective_date', 'desc')
            ->get()
            ->groupBy(['from_currency', 'to_currency']);

        $result = [];
        foreach ($rates as $from => $toCurrencies) {
            foreach ($toCurrencies as $to => $rateList) {
                $result["{$from}_{$to}"] = $rateList->first();
            }
        }

        return $result;
    }
}
