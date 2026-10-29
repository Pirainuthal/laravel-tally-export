<?php

declare(strict_types=1);

namespace Pirainuthal\TallyExport\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Pirainuthal\TallyExport\Exceptions\InvalidVoucherException;
use Pirainuthal\TallyExport\Gst\GstRate;

final class GstRateTest extends TestCase
{
    public function test_converts_to_basis_points(): void
    {
        self::assertSame(1800, GstRate::toBasisPoints(18));
        self::assertSame(1800, GstRate::toBasisPoints('18.00'));
        self::assertSame(25, GstRate::toBasisPoints(0.25));
        self::assertSame(150, GstRate::toBasisPoints('1.5'));
        self::assertSame(0, GstRate::toBasisPoints(0));
    }

    public function test_formats_rates_and_half_rates(): void
    {
        self::assertSame('18', GstRate::format(1800));
        self::assertSame('0.25', GstRate::format(25));
        self::assertSame('9', GstRate::formatHalf(1800));
        self::assertSame('2.5', GstRate::formatHalf(500));
        self::assertSame('0.125', GstRate::formatHalf(25));
        self::assertSame('0.75', GstRate::formatHalf(150));
    }

    public function test_rejects_bad_rates(): void
    {
        $this->expectException(InvalidVoucherException::class);
        GstRate::toBasisPoints('-5');
    }

    public function test_rejects_rates_above_hundred(): void
    {
        $this->expectException(InvalidVoucherException::class);
        GstRate::toBasisPoints(101);
    }
}
