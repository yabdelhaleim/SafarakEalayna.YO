<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
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
])]
class Airport extends Model
{
    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:8',
            'longitude' => 'decimal:8',
            'is_active' => 'boolean',
        ];
    }

    public function fromBookings(): HasMany
    {
        return $this->hasMany(\App\Models\Flight\FlightBooking::class, 'from_airport_id');
    }

    public function toBookings(): HasMany
    {
        return $this->hasMany(\App\Models\Flight\FlightBooking::class, 'to_airport_id');
    }

    public function segmentsFrom(): HasMany
    {
        return $this->hasMany(\App\Models\Flight\FlightSegment::class, 'from_airport_id');
    }

    public function segmentsTo(): HasMany
    {
        return $this->hasMany(\App\Models\Flight\FlightSegment::class, 'to_airport_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeByCountry($query, string $countryCode)
    {
        return $query->where('country_code', $countryCode);
    }

    public function scopeSearch($query, string $search)
    {
        return $query->where(function ($q) use ($search) {
            $q->where('iata_code', 'like', "%{$search}%")
              ->orWhere('city_name_ar', 'like', "%{$search}%")
              ->orWhere('city_name_en', 'like', "%{$search}%")
              ->orWhere('airport_name_ar', 'like', "%{$search}%")
              ->orWhere('airport_name_en', 'like', "%{$search}%");
        });
    }

    public function getFullNameAttribute(): string
    {
        $locale = app()->getLocale();
        if ($locale === 'ar') {
            return "{$this->city_name_ar} - {$this->airport_name_ar} ({$this->iata_code})";
        }
        return "{$this->city_name_en} - {$this->airport_name_en} ({$this->iata_code})";
    }
}
