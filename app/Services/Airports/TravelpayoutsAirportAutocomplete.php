<?php

namespace App\Services\Airports;

use Illuminate\Support\Facades\Http;

class TravelpayoutsAirportAutocomplete
{
    private const string ENDPOINT = 'https://autocomplete.travelpayouts.com/places2';

    /**
     * @return array<string, string> IATA code => label for Filament Select options
     */
    public function searchLabels(string $term): array
    {
        $term = trim($term);
        if (strlen($term) < 2) {
            return [];
        }

        $out = [];
        foreach ($this->fetch($term) as $item) {
            if (($item['type'] ?? '') !== 'airport') {
                continue;
            }
            $code = strtoupper((string) ($item['code'] ?? ''));
            if ($code === '') {
                continue;
            }
            $city = (string) ($item['city_name'] ?? '');
            $name = (string) ($item['name'] ?? '');
            $out[$code] = "{$city} — {$name} ({$code})";
        }

        return $out;
    }

    /**
     * @return array<string, mixed>|null Normalized form defaults
     */
    public function detailsByIata(string $iata): ?array
    {
        $iata = strtoupper(trim($iata));
        if ($iata === '') {
            return null;
        }

        foreach ($this->fetch($iata) as $item) {
            if (($item['type'] ?? '') !== 'airport') {
                continue;
            }
            if (strtoupper((string) ($item['code'] ?? '')) !== $iata) {
                continue;
            }

            return $this->mapToForm($item);
        }

        return null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetch(string $term): array
    {
        $response = Http::timeout(10)
            ->acceptJson()
            ->get(self::ENDPOINT, [
                'term' => $term,
                'locale' => 'en',
                'types[]' => 'airport',
            ]);

        if (! $response->successful()) {
            return [];
        }

        $json = $response->json();

        return is_array($json) ? $json : [];
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function mapToForm(array $item): array
    {
        $lat = $item['coordinates']['lat'] ?? null;
        $lon = $item['coordinates']['lon'] ?? null;
        $countryCode = strtoupper((string) ($item['country_code'] ?? ''));
        $countryEn = (string) ($item['country_name'] ?? '');
        $cityEn = (string) ($item['city_name'] ?? '');
        $airportEn = (string) ($item['name'] ?? '');

        return [
            'iata_code' => strtoupper((string) ($item['code'] ?? '')),
            'icao_code' => null,
            'city_name_en' => $cityEn,
            'city_name_ar' => $cityEn,
            'airport_name_en' => $airportEn,
            'airport_name_ar' => $airportEn,
            'country_code' => $countryCode,
            'country_name_en' => $countryEn,
            'country_name_ar' => $this->countryNameAr($countryCode, $countryEn),
            'latitude' => $lat !== null ? (float) $lat : null,
            'longitude' => $lon !== null ? (float) $lon : null,
            'timezone' => null,
        ];
    }

    private function countryNameAr(string $code, string $englishFallback): string
    {
        return match ($code) {
            'EG' => 'مصر',
            'SA' => 'السعودية',
            'KW' => 'الكويت',
            'AE' => 'الإمارات',
            'JO' => 'الأردن',
            'QA' => 'قطر',
            'BH' => 'البحرين',
            'OM' => 'عُمان',
            'IQ' => 'العراق',
            'LB' => 'لبنان',
            'SY' => 'سوريا',
            'YE' => 'اليمن',
            'SD' => 'السودان',
            'TR' => 'تركيا',
            'DE' => 'ألمانيا',
            'FR' => 'فرنسا',
            'GB' => 'المملكة المتحدة',
            'US' => 'الولايات المتحدة',
            default => $englishFallback,
        };
    }
}
