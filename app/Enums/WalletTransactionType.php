<?php

namespace App\Enums;

enum WalletTransactionType: string
{
    case Send    = 'send';
    case Receive = 'receive';

    public function label(): string
    {
        return match ($this) {
            self::Send    => 'إرسال رصيد',
            self::Receive => 'استقبال رصيد',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Send    => 'warning',
            self::Receive => 'success',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Send    => 'heroicon-o-arrow-up-circle',
            self::Receive => 'heroicon-o-arrow-down-circle',
        };
    }
}
