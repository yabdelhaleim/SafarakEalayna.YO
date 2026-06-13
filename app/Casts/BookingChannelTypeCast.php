<?php

namespace App\Casts;

use App\Enums\BookingChannelType;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Reads legacy booking_channel_type values (manual, online) and maps them to canonical enum cases.
 * Writes always persist the canonical enum backing value.
 */
class BookingChannelTypeCast implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?BookingChannelType
    {
        if ($value === null || $value === '') {
            return null;
        }

        return BookingChannelType::normalize((string) $value);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof BookingChannelType) {
            return $value->value;
        }

        return BookingChannelType::normalize((string) $value)?->value;
    }
}
