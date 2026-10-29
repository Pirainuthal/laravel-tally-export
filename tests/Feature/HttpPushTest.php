<?php

declare(strict_types=1);

namespace Pirainuthal\TallyExport\Tests\Feature;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Pirainuthal\TallyExport\Exceptions\TallyConnectionException;
use Pirainuthal\TallyExport\Exceptions\TallyResponseException;
use Pirainuthal\TallyExport\Facades\Tally;

final class HttpPushTest extends TestCase
{
    public function test_pushes_xml_to_configured_url_and_parses_counts(): void
    {
        Http::fake([
            'tally.test:9000*' => Http::response('<RESPONSE><CREATED>1</CREATED><ALTERED>0</ALTERED><ERRORS>0</ERRORS></RESPONSE>'),
        ]);

        $result = Tally::push(
            Tally::sales()->number('INV-1')->date('2026-04-01')->party('Kaveri Traders')->addLine('Sales', 100)
        );

        self::assertSame(1, $result->created);
        self::assertTrue($result->successful());

        Http::assertSent(static function (Request $request): bool {
            return $request->url() === 'http://tally.test:9000'
                && $request->method() === 'POST'
                && str_contains($request->header('Content-Type')[0] ?? '', 'text/xml')
                && str_contains($request->body(), '<VOUCHERNUMBER>INV-1</VOUCHERNUMBER>')
                && str_contains($request->body(), '<SVCURRENTCOMPANY>Demo Company</SVCURRENTCOMPANY>');
        });
    }

    public function test_reports_tally_errors(): void
    {
        Http::fake([
            '*' => Http::response('<RESPONSE><CREATED>0</CREATED><ERRORS>1</ERRORS><LINEERROR>Ledger &apos;Sales&apos; does not exist!</LINEERROR></RESPONSE>'),
        ]);

        $result = Tally::push('<ENVELOPE/>');

        self::assertFalse($result->successful());
        self::assertSame(1, $result->errors);
        self::assertSame(["Ledger 'Sales' does not exist!"], $result->lineErrors);
    }

    public function test_http_error_status_becomes_connection_exception(): void
    {
        Http::fake(['*' => Http::response('Server error', 500)]);

        $this->expectException(TallyConnectionException::class);
        $this->expectExceptionMessage('answered HTTP 500');
        Tally::push('<ENVELOPE/>');
    }

    public function test_unreachable_tally_becomes_connection_exception(): void
    {
        Http::fake(static fn () => throw new ConnectionException('cURL error 7: Failed to connect'));

        $this->expectException(TallyConnectionException::class);
        $this->expectExceptionMessage('Could not reach Tally at http://tally.test:9000');
        Tally::push('<ENVELOPE/>');
    }

    public function test_garbage_body_becomes_response_exception(): void
    {
        Http::fake(['*' => Http::response('<html>proxy login</html')]);

        $this->expectException(TallyResponseException::class);
        Tally::push('<ENVELOPE/>');
    }
}
