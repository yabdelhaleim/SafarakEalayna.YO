<?php

namespace App\Enums;

enum ApprovalActionType: string
{
    case BOOKING = 'booking';           // حجز جديد
    case TRANSFER = 'transfer';         // تحويل بين حسابات
    case CURRENCY_CONVERSION = 'currency_conversion'; // تحويل عملة
    case PAYMENT = 'payment';           // دفع
    case REFUND = 'refund';             // استرداد

    public function getName(): string
    {
        return match ($this) {
            self::BOOKING => 'حجز جديد',
            self::TRANSFER => 'تحويل بين حسابات',
            self::CURRENCY_CONVERSION => 'تحويل عملة',
            self::PAYMENT => 'دفع',
            self::REFUND => 'استرداد',
        };
    }
}
