<?php

namespace App\Enums;

enum BookingChannelType: string
{
    case SYSTEM = 'SYSTEM';
    case SIGN = 'SIGN';
    case GROUP = 'GROUP';

    public function label(): string
    {
        return match ($this) {
            self::SYSTEM => 'نظام GDS / NDC',
            self::SIGN => 'ساين',
            self::GROUP => 'حجز مجموعة',
        };
    }

    /**
     * Accept canonical enum values plus legacy DB/API aliases.
     */
    public static function normalize(?string $value): ?self
    {
        if ($value === null || $value === '') {
            return null;
        }

        $trimmed = trim($value);
        $upper = strtoupper($trimmed);
        $lower = strtolower($trimmed);

        $resolved = match ($lower) {
            'manual', 'direct', 'sign' => self::SIGN,
            'online', 'website', 'system', 'gds', 'ndc' => self::SYSTEM,
            'group' => self::GROUP,
            default => self::tryFrom($upper) ?? self::tryFrom($trimmed),
        };

        return $resolved;
    }

    /**
     * @return list<string>
     */
    public static function validationValues(): array
    {
        return array_values(array_unique([
            ...array_column(self::cases(), 'value'),
            'manual',
            'online',
            'sign',
            'system',
            'group',
        ]));
    }

    public static function forDropdown(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $case) => [$case->value => $case->label()])
            ->all();
    }
}
