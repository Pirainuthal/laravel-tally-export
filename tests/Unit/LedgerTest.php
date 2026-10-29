<?php

declare(strict_types=1);

namespace Pirainuthal\TallyExport\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Pirainuthal\TallyExport\Exceptions\InvalidGstinException;
use Pirainuthal\TallyExport\Exceptions\InvalidVoucherException;
use Pirainuthal\TallyExport\Gst\GstDutyHead;
use Pirainuthal\TallyExport\Gst\GstRegistrationType;
use Pirainuthal\TallyExport\Support\Side;
use Pirainuthal\TallyExport\TallyLedger;
use Pirainuthal\TallyExport\Tests\Concerns\AssertsTallyXml;

final class LedgerTest extends TestCase
{
    use AssertsTallyXml;

    public function test_customer_ledger_with_gst_details(): void
    {
        $xml = TallyLedger::customer('Kaveri Traders')
            ->gstin('33ABCDE1234F1Z7')
            ->state('Tamil Nadu')
            ->address('12, Gandhi Road', 'Erode')
            ->pincode('638001')
            ->email('accounts@example.com')
            ->openingBalance(5000)
            ->toXml();

        $this->loadXml($xml);
        self::assertSame('All Masters', $this->xpathValue($xml, '//REPORTNAME'));
        self::assertSame('Kaveri Traders', $this->xpathValue($xml, '//LEDGER/@NAME'));
        self::assertSame('Create', $this->xpathValue($xml, '//LEDGER/@ACTION'));
        self::assertSame('Kaveri Traders', $this->xpathValue($xml, '//LEDGER/NAME.LIST/NAME'));
        self::assertSame('Sundry Debtors', $this->xpathValue($xml, '//LEDGER/PARENT'));
        self::assertSame('Yes', $this->xpathValue($xml, '//LEDGER/ISBILLWISEON'));
        self::assertSame('33ABCDE1234F1Z7', $this->xpathValue($xml, '//LEDGER/PARTYGSTIN'));
        self::assertSame('Regular', $this->xpathValue($xml, '//LEDGER/GSTREGISTRATIONTYPE'));
        self::assertSame('Tamil Nadu', $this->xpathValue($xml, '//LEDGER/LEDSTATENAME'));
        self::assertSame('India', $this->xpathValue($xml, '//LEDGER/COUNTRYNAME'));
        self::assertSame('Erode', $this->xpathValue($xml, '//LEDGER/ADDRESS.LIST/ADDRESS[2]'));
        self::assertSame('638001', $this->xpathValue($xml, '//LEDGER/PINCODE'));
        // Debit opening balance is negative, like a debit voucher entry.
        self::assertSame('-5000.00', $this->xpathValue($xml, '//LEDGER/OPENINGBALANCE'));
    }

    public function test_credit_opening_balance_is_positive(): void
    {
        $ledger = TallyLedger::supplier('Paper Mills')->openingBalance(2500, Side::Credit)->build();
        self::assertSame(Side::Credit, $ledger->openingSide);
        self::assertSame('Sundry Creditors', $ledger->parent);

        $negative = TallyLedger::supplier('Paper Mills')->openingBalance(-2500)->build();
        self::assertSame(Side::Credit, $negative->openingSide);
        self::assertSame(250000, $negative->openingPaise);
    }

    public function test_state_from_gstin(): void
    {
        $ledger = TallyLedger::customer('Mysuru Silks')->gstin('29ABCDE1234F1ZW')->stateFromGstin()->build();

        self::assertSame('Karnataka', $ledger->state);
    }

    public function test_duty_ledger(): void
    {
        $xml = TallyLedger::gstDuty('CGST', GstDutyHead::Central)->toXml();

        self::assertSame('Duties & Taxes', $this->xpathValue($xml, '//LEDGER/PARENT'));
        self::assertSame('GST', $this->xpathValue($xml, '//LEDGER/TAXTYPE'));
        self::assertSame('Central Tax', $this->xpathValue($xml, '//LEDGER/GSTDUTYHEAD'));
        self::assertStringContainsString('<PARENT>Duties &amp; Taxes</PARENT>', $xml);
    }

    public function test_other_presets_and_registration_type(): void
    {
        self::assertSame('Sales Accounts', TallyLedger::sales('Sales @ 18%')->build()->parent);
        self::assertSame('Bank Accounts', TallyLedger::bank('Bank')->build()->parent);
        self::assertSame('Indirect Expenses', TallyLedger::roundOff()->build()->parent);

        $ledger = TallyLedger::make('Walk-in Customer', 'Sundry Debtors')
            ->registrationType(GstRegistrationType::Consumer)
            ->build();
        self::assertSame(GstRegistrationType::Consumer, $ledger->registrationType);
        self::assertNull($ledger->billWise);
    }

    public function test_invalid_gstin_is_rejected(): void
    {
        $this->expectException(InvalidGstinException::class);
        TallyLedger::customer('X')->gstin('33ABCDE1234F1Z0');
    }

    public function test_parent_is_required(): void
    {
        $this->expectException(InvalidVoucherException::class);
        $this->expectExceptionMessage('needs a parent group');
        TallyLedger::make('Orphan')->build();
    }
}
