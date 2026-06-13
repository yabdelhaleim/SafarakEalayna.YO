<?php

namespace Tests\Unit;

use App\Enums\BookingChannelType;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BookingChannelTypeTest extends TestCase
{
    #[DataProvider('legacyAliasProvider')]
    public function test_normalize_maps_legacy_aliases(string $input, BookingChannelType $expected): void
    {
        $this->assertSame($expected, BookingChannelType::normalize($input));
    }

    public static function legacyAliasProvider(): array
    {
        return [
            ['manual', BookingChannelType::SIGN],
            ['online', BookingChannelType::SYSTEM],
            ['SIGN', BookingChannelType::SIGN],
            ['SYSTEM', BookingChannelType::SYSTEM],
            ['GROUP', BookingChannelType::GROUP],
        ];
    }

    public function test_validation_values_include_legacy_aliases(): void
    {
        $values = BookingChannelType::validationValues();

        $this->assertContains('manual', $values);
        $this->assertContains('online', $values);
        $this->assertContains('SIGN', $values);
    }
}
