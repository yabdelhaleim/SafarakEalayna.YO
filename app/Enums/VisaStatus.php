<?php

namespace App\Enums;

enum VisaStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case UnderReview = 'under_review';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Issued = 'issued';
    case Cancelled = 'cancelled';
    case Refunded = 'refunded';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'مسودة',
            self::Submitted => 'مُقدَّمة',
            self::UnderReview => 'تحت المراجعة',
            self::Approved => 'مقبولة',
            self::Rejected => 'مرفوضة',
            self::Issued => 'صادرة',
            self::Cancelled => 'ملغاة',
            self::Refunded => 'مستردة',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Draft => 'secondary',
            self::Submitted => 'info',
            self::UnderReview => 'warning',
            self::Approved => 'success',
            self::Rejected => 'danger',
            self::Issued => 'success',
            self::Cancelled => 'gray',
            self::Refunded => 'gray',
        };
    }

    public static function forDropdown(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $case) => [$case->value => $case->label()])
            ->all();
    }
}
