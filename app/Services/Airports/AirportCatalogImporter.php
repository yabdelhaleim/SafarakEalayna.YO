<?php

namespace App\Services\Airports;

use App\Models\Airport;
use Illuminate\Support\Facades\Log;

class AirportCatalogImporter
{
    /**
     * @param  array<string, array<string, mixed>>  $byIata  مفاتيحها رموز IATA (مثل CAI => [...])
     * @return array{imported: int, errors: list<string>}
     */
    public function importKeyedByIata(array $byIata): array
    {
        $imported = 0;
        $errors = [];

        foreach ($byIata as $iataKey => $fields) {
            if (! is_array($fields)) {
                $errors[] = "تخطي مفتاح غير صالح: {$iataKey}";

                continue;
            }

            $iata = strtoupper(is_string($iataKey) ? trim($iataKey) : (string) ($fields['iata_code'] ?? ''));
            if (strlen($iata) < 3 || strlen($iata) > 4) {
                $errors[] = "رمز IATA غير صالح: {$iataKey}";

                continue;
            }

            $payload = array_merge(['iata_code' => $iata], $fields);
            $payload['iata_code'] = $iata;

            if (! $this->hasRequiredFields($payload)) {
                $errors[] = "حقول ناقصة للمطار: {$iata}";

                continue;
            }

            $payload['is_active'] = filter_var($payload['is_active'] ?? true, FILTER_VALIDATE_BOOLEAN);

            try {
                Airport::updateOrCreate(
                    ['iata_code' => $iata],
                    $this->onlyFillable($payload)
                );
                $imported++;
            } catch (\Throwable $e) {
                Log::warning('Airport import failed', ['iata' => $iata, 'message' => $e->getMessage()]);
                $errors[] = "{$iata}: {$e->getMessage()}";
            }
        }

        return ['imported' => $imported, 'errors' => $errors];
    }

    /**
     * @param  array<string, mixed>  $decoded  ناتج json_decode لكائن { "CAI": { ... }, ... }
     * @return array{imported: int, errors: list<string>}
     */
    public function importFromJsonObject(array $decoded): array
    {
        return $this->importKeyedByIata($decoded);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    protected function hasRequiredFields(array $row): bool
    {
        foreach ([
            'city_name_ar',
            'city_name_en',
            'airport_name_ar',
            'airport_name_en',
            'country_code',
            'country_name_ar',
            'country_name_en',
        ] as $key) {
            if (! isset($row[$key]) || $row[$key] === '' || $row[$key] === null) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function onlyFillable(array $payload): array
    {
        $keys = [
            'iata_code',
            'icao_code',
            'city_name_ar',
            'city_name_en',
            'airport_name_ar',
            'airport_name_en',
            'country_code',
            'country_name_ar',
            'country_name_en',
            'latitude',
            'longitude',
            'timezone',
            'is_active',
        ];

        return array_intersect_key($payload, array_flip($keys));
    }
}
