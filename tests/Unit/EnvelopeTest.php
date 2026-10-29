<?php

declare(strict_types=1);

namespace Pirainuthal\TallyExport\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Pirainuthal\TallyExport\Exceptions\InvalidVoucherException;
use Pirainuthal\TallyExport\TallyExporter;
use Pirainuthal\TallyExport\TallyLedger;
use Pirainuthal\TallyExport\TallyVoucher;
use Pirainuthal\TallyExport\Tests\Concerns\AssertsTallyXml;
use Pirainuthal\TallyExport\Xml\EnvelopeWriter;

final class EnvelopeTest extends TestCase
{
    use AssertsTallyXml;

    private const FIXTURES = __DIR__.'/../Fixtures';

    protected function setUp(): void
    {
        TallyVoucher::useDefaults(null);
    }

    public function test_sales_and_receipt_envelope_matches_snapshot(): void
    {
        $xml = (new TallyExporter(new EnvelopeWriter('Demo Company')))->export([
            TallyVoucher::sales()
                ->number('INV-2026-0042')
                ->date('2026-04-15')
                ->party('Kaveri Traders')
                ->partyGstin('33ABCDE1234F1Z7')
                ->placeOfSupply('Tamil Nadu')
                ->narration('Being goods sold')
                ->addLine('Sales @ 18%', '1234.50', gstRate: 18)
                ->applyGst(interState: false)
                ->roundOff()
                ->billWise(),
            TallyVoucher::receipt()
                ->number('RCT-0007')
                ->date('2026-04-30')
                ->party('Kaveri Traders', 1457)
                ->depositTo('State Bank Current A/c')
                ->againstInvoice('INV-2026-0042'),
        ]);

        $this->assertBalancedVouchers($xml);
        self::assertStringEqualsFile(self::FIXTURES.'/sales-and-receipt.xml', $xml);
    }

    public function test_ledger_envelope_matches_snapshot(): void
    {
        $xml = (new TallyExporter)->exportLedgers([
            TallyLedger::customer('Kaveri Traders')->gstin('33ABCDE1234F1Z7')->state('Tamil Nadu'),
            TallyLedger::sales('Sales @ 18%')->build(),
        ]);

        self::assertStringEqualsFile(self::FIXTURES.'/ledgers.xml', $xml);
    }

    public function test_envelope_structure(): void
    {
        $xml = TallyVoucher::journal()->number('J1')->date('2026-04-01')->debit('A', 1)->credit('B', 1)->toXml();
        self::assertSame(1, $this->countElements($xml, '/ENVELOPE/HEADER/TALLYREQUEST'));
        self::assertSame('Import Data', $this->xpathValue($xml, '/ENVELOPE/HEADER/TALLYREQUEST'));
        self::assertSame('Vouchers', $this->xpathValue($xml, '/ENVELOPE/BODY/IMPORTDATA/REQUESTDESC/REPORTNAME'));
        self::assertSame(1, $this->countElements($xml, '/ENVELOPE/BODY/IMPORTDATA/REQUESTDATA/TALLYMESSAGE/VOUCHER'));
        self::assertSame(0, $this->countElements($xml, '//STATICVARIABLES'), 'No company -> no STATICVARIABLES');
    }

    public function test_one_tallymessage_per_voucher(): void
    {
        $vouchers = [];
        for ($i = 1; $i <= 3; $i++) {
            $vouchers[] = TallyVoucher::journal()->number("J{$i}")->date('2026-04-01')->debit('A', $i)->credit('B', $i)->build();
        }

        $xml = (new EnvelopeWriter)->write($vouchers);

        self::assertSame(3, $this->countElements($xml, '//TALLYMESSAGE'));
        $this->assertBalancedVouchers($xml);
    }

    public function test_cannot_mix_masters_and_vouchers(): void
    {
        $this->expectException(InvalidVoucherException::class);
        $this->expectExceptionMessage('Cannot mix');

        (new EnvelopeWriter)->write([
            TallyLedger::sales('Sales')->build(),
            TallyVoucher::journal()->number('J')->date('2026-04-01')->debit('A', 1)->credit('B', 1)->build(),
        ]);
    }

    public function test_unicode_names_survive_and_invalid_utf8_is_scrubbed(): void
    {
        $xml = TallyVoucher::journal()->number('J')->date('2026-04-01')
            ->debit('கடை செலவு', 1)
            ->credit("Cash \xC3\x28", 1)
            ->toXml();

        $this->loadXml($xml);
        self::assertStringContainsString('<LEDGERNAME>கடை செலவு</LEDGERNAME>', $xml);
    }
}
