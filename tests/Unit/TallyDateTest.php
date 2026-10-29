<?php

declare(strict_types=1);

namespace Pirainuthal\TallyExport\Tests\Unit;

use DateTime;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pirainuthal\TallyExport\Exceptions\InvalidVoucherException;
use Pirainuthal\TallyExport\Support\TallyDate;

final class TallyDateTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function equivalentInputs(): iterable
    {
        yield 'iso' => ['2026-04-01'];
        yield 'compact' => ['20260401'];
        yield 'dd-mm-yyyy' => ['01-04-2026'];
        yield 'dd/mm/yyyy' => ['01/04/2026'];
    }

    #[DataProvider('equivalentInputs')]
    public function test_formats_strings_as_yyyymmdd(string $input): void
    {
        self::assertSame('20260401', TallyDate::format($input));
    }

    public function test_accepts_date_objects(): void
    {
        self::assertSame('20270331', TallyDate::format(new DateTimeImmutable('2027-03-31 23:59:59')));
        self::assertSame('20270331', TallyDate::format(new DateTime('2027-03-31')));
    }

    public function test_rejects_impossible_dates(): void
    {
        $this->expectException(InvalidVoucherException::class);
        TallyDate::format('2026-02-30');
    }

    public function test_rejects_ambiguous_formats(): void
    {
        $this->expectException(InvalidVoucherException::class);
        TallyDate::format('04/01/26');
    }
}
