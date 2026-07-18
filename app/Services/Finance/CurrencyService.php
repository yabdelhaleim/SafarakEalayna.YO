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

        // 1. Try direct rate
        $rate = ExchangeRate::where('from_currency', $fromCurrency)
            ->where('to_currency', $toCurrency)
            ->where('effective_date', '<=', $date)
            ->where('is_active', true)
            ->orderBy('effective_date', 'desc')
            ->first();

        if ($rate) {
            $convertedAmount = $amount * $rate->rate;

            return [
                'from_amount' => $amount,
                'from_currency' => $fromCurrency,
                'to_amount' => $convertedAmount,
                'to_currency' => $toCurrency,
                'rate' => (float) $rate->rate,
                'rate_date' => $rate->effective_date,
            ];
        }

        // 2. Try inverse rate (e.g., EGP to USD using USD to EGP rate)
        $inverseRate = ExchangeRate::where('from_currency', $toCurrency)
            ->where('to_currency', $fromCurrency)
            ->where('effective_date', '<=', $date)
            ->where('is_active', true)
            ->orderBy('effective_date', 'desc')
            ->first();

        if ($inverseRate && $inverseRate->rate > 0) {
            $rateValue = 1.0 / $inverseRate->rate;
            $convertedAmount = $amount * $rateValue;

            return [
                'from_amount' => $amount,
                'from_currency' => $fromCurrency,
                'to_amount' => $convertedAmount,
                'to_currency' => $toCurrency,
                'rate' => $rateValue,
                'rate_date' => $inverseRate->effective_date,
            ];
        }

        // 2.5 Try currencies table (base settings)
        if ($fromCurrency === 'EGP' || $toCurrency === 'EGP') {
            $foreign = $fromCurrency === 'EGP' ? $toCurrency : $fromCurrency;
            $dbCurrency = DB::table('currencies')
                ->where('is_active', true)
                ->whereRaw('upper(code) = ?', [$foreign])
                ->first();

            if ($dbCurrency && (float) $dbCurrency->exchange_rate > 0) {
                $rateValue = $fromCurrency === 'EGP' ? (1.0 / (float) $dbCurrency->exchange_rate) : (float) $dbCurrency->exchange_rate;
                $convertedAmount = $amount * $rateValue;

                return [
                    'from_amount' => $amount,
                    'from_currency' => $fromCurrency,
                    'to_amount' => $convertedAmount,
                    'to_currency' => $toCurrency,
                    'rate' => $rateValue,
                    'rate_date' => now()->toDateString(),
                ];
            }
        }

        // 3. Fallback: try converting through EGP if both currencies are foreign and direct rate doesn't exist
        if ($fromCurrency !== 'EGP' && $toCurrency !== 'EGP') {
            try {
                // Convert from $fromCurrency to EGP
                $toEgp = $this->convert($amount, $fromCurrency, 'EGP', $date);
                // Convert from EGP to $toCurrency
                $fromEgp = $this->convert($toEgp['to_amount'], 'EGP', $toCurrency, $date);

                return [
                    'from_amount' => $amount,
                    'from_currency' => $fromCurrency,
                    'to_amount' => $fromEgp['to_amount'],
                    'to_currency' => $toCurrency,
                    'rate' => $amount > 0 ? ($fromEgp['to_amount'] / $amount) : 0.0,
                    'rate_date' => $toEgp['rate_date'],
                ];
            } catch (\Exception $e) {
                // If it fails, let it throw the standard exception below
            }
        }

        throw new \Exception("لا يوجد سعر صرف متاح من {$fromCurrency} إلى {$toCurrency} في تاريخ {$date}");
    }

    /**
     * إدخال سعر صرف جديد
     */
    public function setExchangeRate(array $data): ExchangeRate
    {
        return DB::transaction(function () use ($data) {
            $effectiveDate = $data['effective_date'] ?? now()->toDateString();

            // Phase 3.5 fix: use updateOrCreate so re-running with same
            // (from_currency, to_currency, effective_date) updates the rate
            // instead of violating the unique key.
            $rate = ExchangeRate::updateOrCreate(
                [
                    'from_currency' => $data['from_currency'],
                    'to_currency' => $data['to_currency'],
                    'effective_date' => $effectiveDate,
                ],
                [
                    'rate' => $data['rate'],
                    'is_active' => $data['is_active'] ?? true,
                    'created_by' => Auth::id(),
                ]
            );

            // Audit Log
            AuditLog::create([
                'user_id' => Auth::id(),
                'action' => $rate->wasRecentlyCreated ? 'create_exchange_rate' : 'update_exchange_rate',
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
