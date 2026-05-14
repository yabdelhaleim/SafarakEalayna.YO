<?php

namespace App\Enums;

enum PassengerType: string
{
    case ADULT = 'adult';
    case CHILD = 'child';
    case INFANT = 'infant';

    public function label(): string
    {
        return match($this) {
            self::ADULT => 'بالغ (12+ سنة)',
            self::CHILD => 'طفل (2-12 سنة)',
            self::INFANT => 'رضيع (أقل من سنتين)',
        };
    }

    public function ageRange(): string
    {
        return match($this) {
            self::ADULT => '12+',
            self::CHILD => '2-12',
            self::INFANT => '0-2',
        };
    }
}
