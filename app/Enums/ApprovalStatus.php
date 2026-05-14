<?php

namespace App\Enums;

enum ApprovalStatus: string
{
    case PENDING = 'pending';
    case APPROVED = 'approved';
    case REJECTED = 'rejected';
    case CANCELLED = 'cancelled';

    public function getName(): string
    {
        return match ($this) {
            self::PENDING => 'بانتظار الموافقة',
            self::APPROVED => 'تمت الموافقة',
            self::REJECTED => 'مرفوض',
            self::CANCELLED => 'ملغي',
        };
    }
}
