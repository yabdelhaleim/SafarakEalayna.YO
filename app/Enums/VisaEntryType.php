<?php

namespace App\Enums;

enum VisaEntryType: string
{
    case Single = 'single';
    case Multiple = 'multiple';
    case Triple = 'triple';

    public function label(): string
    {
        return match($this) {
            self::Single => 'دخول واحد',
            self::Multiple => 'دخول متعدد',
            self::Triple => 'دخول ثلاثي',
        };
    }

    public static function forDropdown(): array
    {
        return [
            self::Single->value => 'دخول واحد',
            self::Multiple->value => 'دخول متعدد',
            self::Triple->value => 'دخول ثلاثي',
        ];
    }
}
