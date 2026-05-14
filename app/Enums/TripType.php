<?php

namespace App\Enums;

enum TripType: string
{
    case ONE_WAY = 'one_way';
    case ROUND_TRIP = 'round_trip';
    case MULTI_CITY = 'multi_city';

    public function label(): string
    {
        return match($this) {
            self::ONE_WAY => 'ذهاب فقط',
            self::ROUND_TRIP => 'ذهاب وعودة',
            self::MULTI_CITY => 'مدن متعددة',
        };
    }

    public function icon(): string
    {
        return match($this) {
            self::ONE_WAY => 'heroicon-o-arrow-right',
            self::ROUND_TRIP => 'heroicon-o-arrow-path',
            self::MULTI_CITY => 'heroicon-o-arrow-up-right',
        };
    }

    public function description(): string
    {
        return match($this) {
            self::ONE_WAY => 'رحلة باتجاه واحد فقط',
            self::ROUND_TRIP => 'رحلة ذهاب وعودة لنفس الوجهة',
            self::MULTI_CITY => 'رحلات لعدة وجهات مختلفة',
        };
    }
}
