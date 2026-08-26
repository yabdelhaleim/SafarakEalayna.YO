<?php

namespace Tests\Feature\Flight;

use App\Models\Setting\Currency;

/**
 * PHASE G FX fixture helper (2026-08-26).
 *
 * Shared across Flight test files that trigger cross-currency prepaid
 * recharges. CurrencyService::convert() consults the `currencies` table
 * as its last-resort fallback before throwing — without seeding these
 * rows the test environment cannot represent the system's own rates.
 *
 * The rates below match the booking-level exchange_rate values used
 * throughout the Flight multi-currency test suite, so the prepaid
 * recharge conversions produce values consistent with the booking's
 * own purchase_price / selling_price fields.
 */
trait FlightFxFixtureTrait
{
    /**
     * Seed the system-wide `currencies` table with the FX rates the Flight
     * multi-currency tests rely on. Idempotent — safe to call multiple times.
     */
    protected function seedFlightExchangeRates(): void
    {
        $rates = [
            ['code' => 'EGP', 'name_ar' => 'جنيه مصري',  'name_en' => 'Egyptian Pound', 'symbol' => 'E£', 'exchange_rate' => 1.0,   'is_active' => true, 'order' => 1],
            ['code' => 'USD', 'name_ar' => 'دولار أمريكي', 'name_en' => 'US Dollar',      'symbol' => '$',  'exchange_rate' => 50.0,  'is_active' => true, 'order' => 2],
            ['code' => 'SAR', 'name_ar' => 'ريال سعودي',  'name_en' => 'Saudi Riyal',    'symbol' => 'ر.س','exchange_rate' => 13.0,  'is_active' => true, 'order' => 3],
            ['code' => 'KWD', 'name_ar' => 'دينار كويتي',  'name_en' => 'Kuwaiti Dinar',  'symbol' => 'د.ك','exchange_rate' => 160.0, 'is_active' => true, 'order' => 4],
            ['code' => 'EUR', 'name_ar' => 'يورو',         'name_en' => 'Euro',           'symbol' => '€',  'exchange_rate' => 52.3,  'is_active' => true, 'order' => 5],
            ['code' => 'GBP', 'name_ar' => 'جنيه إسترليني','name_en' => 'British Pound',  'symbol' => '£',  'exchange_rate' => 61.2,  'is_active' => true, 'order' => 6],
        ];

        foreach ($rates as $row) {
            Currency::updateOrCreate(['code' => $row['code']], $row);
        }
    }
}