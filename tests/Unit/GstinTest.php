<?php

declare(strict_types=1);

namespace Pirainuthal\TallyExport\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pirainuthal\TallyExport\Exceptions\InvalidGstinException;
use Pirainuthal\TallyExport\Gst\Gstin;

final class GstinTest extends TestCase
{
    /**
     * Synthetic GSTINs with correct check characters, plus the example
     * GSTIN used in public GSTN documentation.
     *
     * @return iterable<string, array{string}>
     */
    public static function validGstins(): iterable
    {
        yield 'tamil nadu' => ['33ABCDE1234F1Z7'];
        yield 'karnataka' => ['29ABCDE1234F1ZW'];
        yield 'maharashtra' => ['27PQRST6789K1ZW'];
        yield 'synthetic example' => ['27ABCDE1234F1Z0'];
    }

    #[DataProvider('validGstins')]
    public function test_valid_gstins(string $gstin): void
    {
        self::assertTrue(Gstin::isValid($gstin));
        self::assertSame($gstin, Gstin::parse($gstin)->value);
    }

    public function test_check_character(): void
    {
        self::assertSame('7', Gstin::checkCharacter('33ABCDE1234F1Z'));
        self::assertSame('0', Gstin::checkCharacter('27ABCDE1234F1Z'));
    }

    public function test_normalises_case_and_whitespace(): void
    {
        self::assertSame('33ABCDE1234F1Z7', Gstin::parse(' 33abcde1234f1z7 ')->value);
    }

    public function test_exposes_state_and_pan(): void
    {
        $gstin = Gstin::parse('33ABCDE1234F1Z7');

        self::assertSame('33', $gstin->stateCode());
        self::assertSame('Tamil Nadu', $gstin->stateName());
        self::assertSame('ABCDE1234F', $gstin->pan());
        self::assertSame('33ABCDE1234F1Z7', (string) $gstin);
    }

    public function test_rejects_a_wrong_check_character_with_the_expected_one(): void
    {
        $this->expectException(InvalidGstinException::class);
        $this->expectExceptionMessage('check character should be "7"');
        Gstin::parse('33ABCDE1234F1Z8');
    }

    public function test_rejects_single_transposition(): void
    {
        self::assertFalse(Gstin::isValid('33ABCDE1243F1Z7'));
    }

    public function test_rejects_unknown_state_code(): void
    {
        $first14 = '40ABCDE1234F1Z';
        $this->expectExceptionMessage('unknown state code "40"');
        Gstin::parse($first14.Gstin::checkCharacter($first14));
    }

    public function test_rejects_wrong_length(): void
    {
        self::assertFalse(Gstin::isValid('33ABCDE1234F1Z'));
        self::assertFalse(Gstin::isValid(''));
    }
}
