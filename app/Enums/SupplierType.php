<?php

namespace App\Enums;

enum SupplierType: string
{
    case Airline = 'airline';
    case BusCompany = 'bus_company';
    case Hotel = 'hotel';
    case VisaProvider = 'visa_provider';
    case ServiceProvider = 'service_provider';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Airline => 'Airline',
            self::BusCompany => 'Bus Company',
            self::Hotel => 'Hotel',
            self::VisaProvider => 'Visa Provider',
            self::ServiceProvider => 'Service Provider',
            self::Other => 'Other',
        };
    }
}
