<?php

namespace App\Enums;

enum PerformanceRating
{
    case Excellent = 'excellent';
    case Good = 'good';
    case Average = 'average';
    case Poor = 'poor';

    public function label(): string
    {
        return match ($this) {
            self::Excellent => 'Excellent',
            self::Good => 'Good',
            self::Average => 'Average',
            self::Poor => 'Poor',
        };
    }
}
