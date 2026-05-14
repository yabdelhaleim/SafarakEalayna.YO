<?php

namespace App\Enums;

enum VisaType: string
{
    case Tourist = 'tourist';
    case Business = 'business';
    case Visit = 'visit';
    case Transit = 'transit';
    case Work = 'work';
    case Student = 'student';
    case Umrah = 'umrah';
    case Hajj = 'hajj';
    case Residence = 'residence';

    public function label(): string
    {
        return match($this) {
            self::Tourist => 'سياحية',
            self::Business => 'عمل',
            self::Visit => 'زيارة',
            self::Transit => 'عبور (ترانزيت)',
            self::Work => 'عمل',
            self::Student => 'دراسة',
            self::Umrah => 'عمرة',
            self::Hajj => 'حج',
            self::Residence => 'إقامة',
        };
    }

    public static function forDropdown(): array
    {
        return [
            self::Tourist->value => 'سياحية',
            self::Business->value => 'عمل',
            self::Visit->value => 'زيارة',
            self::Transit->value => 'عبور (ترانزيت)',
            self::Work->value => 'عمل',
            self::Student->value => 'دراسة',
            self::Umrah->value => 'عمرة',
            self::Hajj->value => 'حج',
            self::Residence->value => 'إقامة',
        ];
    }
}
