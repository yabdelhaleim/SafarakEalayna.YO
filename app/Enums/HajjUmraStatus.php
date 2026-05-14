<?php

namespace App\Enums;

enum HajjUmraStatus: string
{
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case Refunded = 'refunded';

    public function label(): string
    {
        return match($this) {
            self::Pending => 'قيد الانتظار',
            self::Confirmed => 'مؤكد',
            self::InProgress => 'قيد التنفيذ',
            self::Completed => 'مكتمل',
            self::Cancelled => 'ملغي',
            self::Refunded => 'مسترد',
        };
    }

    public function color(): string
    {
        return match($this) {
            self::Pending => 'warning',
            self::Confirmed => 'info',
            self::InProgress => 'primary',
            self::Completed => 'success',
            self::Cancelled => 'danger',
            self::Refunded => 'secondary',
        };
    }

    public static function forDropdown(): array
    {
        return [
            self::Pending->value => 'قيد الانتظار',
            self::Confirmed->value => 'مؤكد',
            self::InProgress->value => 'قيد التنفيذ',
            self::Completed->value => 'مكتمل',
            self::Cancelled->value => 'ملغي',
            self::Refunded->value => 'مسترد',
        ];
    }
}
