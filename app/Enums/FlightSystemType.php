<?php

namespace App\Enums;

enum FlightSystemType: string
{
    // GDS Systems
    case Amadeus = 'Amadeus';
    case NDC = 'NDC';
    case NDC_X = 'NDC_X';
    case Sabre = 'Sabre';
    case Galileo = 'Galileo';
    case ThreeTP = '3TP';

    // Airlines (Signatories)
    case Jazeera = 'الجزيرة';
    case SaudiArabian = 'العربية';
    case Nesma = 'نسما';
    case AirCairo = 'اير كايرو';

    // Groups
    case AlShalla = 'الشعلة';
    case Voyage = 'فوياج';
    case AlAla = 'العلا';
    case AlMuhajir = 'المهاجر';
    case Lugano = 'لوجانو';

    // Other
    case Manual = 'manual';
    case Online = 'online';
    case GDS = 'gds';
    case API = 'api';
    case Other = 'other';

    public function label(): string
    {
        return match($this) {
            self::Manual => 'يدوي',
            self::Online => 'أونلاين',
            self::GDS => 'نظام حجز عالمي',
            self::API => 'API',
            self::NDC => 'NDC',
            self::NDC_X => 'NDC X',
            self::ThreeTP => '3TP',
            self::Amadeus => 'Amadeus',
            self::Sabre => 'Sabre',
            self::Galileo => 'Galileo',
            self::Jazeera => 'الجزيرة',
            self::SaudiArabian => 'العربية',
            self::Nesma => 'نسما',
            self::AirCairo => 'اير كايرو',
            self::AlShalla => 'الشعلة',
            self::Voyage => 'فوياج',
            self::AlAla => 'العلا',
            self::AlMuhajir => 'المهاجر',
            self::Lugano => 'لوجانو',
            self::Other => 'أخرى',
        };
    }

    public static function forDropdown(): array
    {
        return [
            'gds' => '=== GDS Systems ===',
            self::Amadeus->value => 'Amadeus',
            self::NDC->value => 'NDC',
            self::NDC_X->value => 'NDC X',
            self::Sabre->value => 'Sabre',
            self::Galileo->value => 'Galileo',
            self::ThreeTP->value => '3TP',
            'airlines' => '=== Airlines ===',
            self::Jazeera->value => 'الجزيرة',
            self::SaudiArabian->value => 'العربية',
            self::Nesma->value => 'نسما',
            self::AirCairo->value => 'اير كايرو',
            'groups' => '=== Groups ===',
            self::AlShalla->value => 'الشعلة',
            self::Voyage->value => 'فوياج',
            self::AlAla->value => 'العلا',
            self::AlMuhajir->value => 'المهاجر',
            self::Lugano->value => 'لوجانو',
            'other' => '=== Other ===',
            self::Manual->value => 'يدوي',
            self::Online->value => 'أونلاين',
            self::GDS->value => 'GDS',
            self::API->value => 'API',
            self::Other->value => 'أخرى',
        ];
    }
}
