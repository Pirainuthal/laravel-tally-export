<?php

declare(strict_types=1);

namespace Pirainuthal\TallyExport\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Pirainuthal\TallyExport\Exceptions\TallyImportException;
use Pirainuthal\TallyExport\Exceptions\TallyResponseException;
use Pirainuthal\TallyExport\Http\ImportResult;

final class ImportResultTest extends TestCase
{
    public function test_parses_bare_response(): void
    {
        $result = ImportResult::fromXml("<RESPONSE>\n <CREATED>2</CREATED>\n <ALTERED>1</ALTERED>\n <ERRORS>0</ERRORS>\n</RESPONSE>");

        self::assertSame(2, $result->created);
        self::assertSame(1, $result->altered);
        self::assertSame(0, $result->errors);
        self::assertTrue($result->successful());
        self::assertSame($result, $result->throwIfFailed());
    }

    public function test_parses_envelope_import_result_with_last_voucher_id(): void
    {
        $body = (string) file_get_contents(__DIR__.'/../Fixtures/response-success.xml');
        $result = ImportResult::fromXml($body);

        self::assertSame(1, $result->created);
        self::assertSame(0, $result->ignored);
        self::assertSame(1234, $result->lastVoucherId);
        self::assertTrue($result->successful());
    }

    public function test_line_error_is_reported_even_without_an_errors_counter(): void
    {
        $result = ImportResult::fromXml('<RESPONSE><LINEERROR>Ledger \'Sales\' does not exist!</LINEERROR></RESPONSE>');

        self::assertSame(1, $result->errors);
        self::assertSame(["Ledger 'Sales' does not exist!"], $result->lineErrors);
        self::assertFalse($result->successful());

        $this->expectException(TallyImportException::class);
        $this->expectExceptionMessage("First error: Ledger 'Sales' does not exist!");
        $result->throwIfFailed();
    }

    public function test_exceptions_count_as_failure(): void
    {
        $result = ImportResult::fromXml('<RESPONSE><CREATED>0</CREATED><ERRORS>0</ERRORS><EXCEPTIONS>1</EXCEPTIONS></RESPONSE>');

        self::assertFalse($result->successful());
    }

    public function test_handles_bom_and_utf16(): void
    {
        $xml = '<?xml version="1.0" encoding="UTF-16"?><RESPONSE><CREATED>3</CREATED><ERRORS>0</ERRORS></RESPONSE>';
        $utf16 = "\xFF\xFE".mb_convert_encoding($xml, 'UTF-16LE', 'UTF-8');

        self::assertSame(3, ImportResult::fromXml($utf16)->created);
        self::assertSame(3, ImportResult::fromXml("\xEF\xBB\xBF".$xml)->created);
    }

    public function test_rejects_non_xml_and_unrelated_xml(): void
    {
        try {
            ImportResult::fromXml('<html><body>Not Tally</body>');
            self::fail('Expected TallyResponseException');
        } catch (TallyResponseException $e) {
            self::assertStringContainsString('not well-formed', $e->getMessage());
        }

        $this->expectException(TallyResponseException::class);
        $this->expectExceptionMessage('no CREATED/ALTERED/ERRORS');
        ImportResult::fromXml('<ENVELOPE><HEADER><STATUS>1</STATUS></HEADER></ENVELOPE>');
    }

    public function test_rejects_empty_body(): void
    {
        $this->expectExceptionMessage('empty response');
        ImportResult::fromXml("  \n");
    }
}
