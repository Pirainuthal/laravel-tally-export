<?php

declare(strict_types=1);

namespace Pirainuthal\TallyExport\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pirainuthal\TallyExport\Exceptions\InvalidVoucherException;
use Pirainuthal\TallyExport\Support\Amount;

final class AmountTest extends TestCase
{
    /**
     * @return iterable<string, array{int|float|string, int}>
     */
    public static function amounts(): iterable
    {
        yield 'int rupees' => [1180, 118000];
        yield 'float' => [1180.5, 118050];
        yield 'float half up' => [1.005, 101];
        yield 'float classic 0.1+0.2' => [0.1 + 0.2, 30];
        yield 'string' => ['1180.50', 118050];
        yield 'indian grouping' => ['1,18,000.75', 11800075];
        yield 'third decimal rounds up' => ['10.005', 1001];
        yield 'third decimal rounds down' => ['10.004', 1000];
        yield 'negative half away from zero' => ['-10.005', -1001];
        yield 'trailing dot' => ['5.', 500];
        yield 'plus sign' => ['+7.1', 710];
        yield 'zero' => ['0', 0];
        yield 'float with many digits' => [12345678.005, 1234567801];
        yield 'tiny float' => [1e-5, 0];
        yield 'negative float' => [-2.675, -268];
    }

    public function test_float_parsing_does_not_depend_on_the_precision_ini_setting(): void
    {
        $previous = ini_set('precision', '17');

        try {
            // (string) 1.005 is "1.0049999999999999" with precision 17.
            self::assertSame(101, Amount::toPaise(1.005));
            self::assertSame(30, Amount::toPaise(0.1 + 0.2));
        } finally {
            ini_set('precision', (string) $previous);
        }
    }

    #[DataProvider('amounts')]
    public function test_to_paise(int|float|string $input, int $expected): void
    {
        self::assertSame($expected, Amount::toPaise($input));
    }

    public function test_rejects_non_numeric_strings(): void
    {
        $this->expectException(InvalidVoucherException::class);
        Amount::toPaise('12abc');
    }

    public function test_rejects_infinite_floats(): void
    {
        $this->expectException(InvalidVoucherException::class);
        Amount::toPaise(INF);
    }

    public function test_format(): void
    {
        self::assertSame('1180.00', Amount::format(118000));
        self::assertSame('-1180.05', Amount::format(-118005));
        self::assertSame('0.07', Amount::format(7));
        self::assertSame('-0.07', Amount::format(-7));
        self::assertSame('0.00', Amount::format(0));
    }

    public function test_percent_of_rounds_half_away_from_zero(): void
    {
        // 9% of 1234.50 = 111.105 -> 111.11
        self::assertSame(11111, Amount::percentOf(123450, 1800, 2));
        // 18% of 1234.50 = 222.21 exactly
        self::assertSame(22221, Amount::percentOf(123450, 1800));
        // 0.125% of 100.00 = 0.125 -> 0.13
        self::assertSame(13, Amount::percentOf(10000, 25, 2));
        self::assertSame(-11111, Amount::percentOf(-123450, 1800, 2));
    }

    public function test_round_to_rupee(): void
    {
        self::assertSame(145700, Amount::roundToRupee(145672));
        self::assertSame(145600, Amount::roundToRupee(145612));
        self::assertSame(145700, Amount::roundToRupee(145650));
        self::assertSame(145600, Amount::roundToRupee(145649));
        self::assertSame(-145700, Amount::roundToRupee(-145650));
    }
}
