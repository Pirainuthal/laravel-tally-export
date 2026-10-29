<?php

declare(strict_types=1);

namespace Pirainuthal\TallyExport\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Pirainuthal\TallyExport\Builders\SalesVoucherBuilder;
use Pirainuthal\TallyExport\Exceptions\InvalidGstinException;
use Pirainuthal\TallyExport\Exceptions\InvalidVoucherException;
use Pirainuthal\TallyExport\Exceptions\UnbalancedVoucherException;
use Pirainuthal\TallyExport\Gst\GstLedgers;
use Pirainuthal\TallyExport\Support\Side;
use Pirainuthal\TallyExport\TallyVoucher;
use Pirainuthal\TallyExport\Tests\Concerns\AssertsTallyXml;

final class SalesVoucherTest extends TestCase
{
    use AssertsTallyXml;

    protected function setUp(): void
    {
        TallyVoucher::useDefaults(null);
    }

    private function invoice(): SalesVoucherBuilder
    {
        return TallyVoucher::sales()
            ->number('INV-001')
            ->date('2026-04-15')
            ->party('Kaveri Traders');
    }

    public function test_intra_state_invoice_debits_party_and_credits_sales_and_cgst_sgst(): void
    {
        $xml = $this->invoice()
            ->addLine('Sales @ 18%', 1000, gstRate: 18)
            ->applyGst(interState: false)
            ->toXml();

        $this->assertBalancedVouchers($xml);
        self::assertSame([
            'Kaveri Traders' => '-1180.00',
            'Sales @ 18%' => '1000.00',
            'CGST' => '90.00',
            'SGST' => '90.00',
        ], $this->entryAmounts($xml));
        self::assertSame('Yes', $this->xpathValue($xml, '//ALLLEDGERENTRIES.LIST[1]/ISPARTYLEDGER'));
        self::assertSame('Sales', $this->xpathValue($xml, '//VOUCHER/@VCHTYPE'));
        self::assertSame('20260415', $this->xpathValue($xml, '//VOUCHER/DATE'));
        self::assertSame('INV-001', $this->xpathValue($xml, '//VOUCHER/VOUCHERNUMBER'));
        self::assertSame('Kaveri Traders', $this->xpathValue($xml, '//VOUCHER/PARTYLEDGERNAME'));
    }

    public function test_inter_state_invoice_uses_igst(): void
    {
        $xml = $this->invoice()
            ->addLine('Sales Interstate', 1000, gstRate: 18)
            ->applyGst(interState: true)
            ->toXml();

        $this->assertBalancedVouchers($xml);
        self::assertSame([
            'Kaveri Traders' => '-1180.00',
            'Sales Interstate' => '1000.00',
            'IGST' => '180.00',
        ], $this->entryAmounts($xml));
    }

    public function test_tax_is_computed_per_rate_and_rate_placeholders_are_resolved(): void
    {
        $voucher = $this->invoice()
            ->addLine('Sales', 1000, gstRate: 18)
            ->addLine('Sales', 500, gstRate: 5)
            ->addLine('Sales', 200, gstRate: 18)
            ->applyGst(false, new GstLedgers('Output CGST {rate}%', 'Output SGST {rate}%', 'Output IGST {rate}%'))
            ->build();

        $amounts = [];
        foreach ($voucher->entries as $entry) {
            $amounts[$entry->ledgerName] = $entry->paise;
        }

        self::assertSame([
            'Kaveri Traders' => 194100, // 1700 + 2 x 108 + 2 x 12.50
            'Sales' => 170000,          // lines on the same ledger are merged
            'Output CGST 9%' => 10800,  // 9% of 1200
            'Output SGST 9%' => 10800,
            'Output CGST 2.5%' => 1250, // 2.5% of 500
            'Output SGST 2.5%' => 1250,
        ], $amounts);
        self::assertSame(0, $voucher->signedSum());
    }

    public function test_round_off_down_is_a_debit(): void
    {
        // 1234.00 + 2 x 9% (111.06) = 1456.12 -> 1456.00
        $xml = $this->invoice()
            ->addLine('Sales', '1234.00', gstRate: 18)
            ->applyGst(false)
            ->roundOff()
            ->toXml();

        $this->assertBalancedVouchers($xml);
        $amounts = $this->entryAmounts($xml);
        self::assertSame('-1456.00', $amounts['Kaveri Traders']);
        self::assertSame('111.06', $amounts['CGST']);
        self::assertSame('-0.12', $amounts['Round Off']);
    }

    public function test_round_off_up_after_half_paisa_tax_rounding(): void
    {
        // 1234.50 + 2 x 9% (111.105 -> 111.11) = 1456.72 -> 1457.00
        $xml = $this->invoice()
            ->addLine('Sales', '1234.50', gstRate: 18)
            ->applyGst(false)
            ->roundOff()
            ->toXml();

        $this->assertBalancedVouchers($xml);
        $amounts = $this->entryAmounts($xml);
        self::assertSame('-1457.00', $amounts['Kaveri Traders']);
        self::assertSame('111.11', $amounts['SGST']);
        self::assertSame('0.28', $amounts['Round Off']);
    }

    public function test_round_off_up_is_a_credit(): void
    {
        $voucher = $this->invoice()->addLine('Sales', '999.50')->roundOff('Rounding')->build();

        $roundOff = $voucher->entries[2];
        self::assertSame('Rounding', $roundOff->ledgerName);
        self::assertSame(Side::Credit, $roundOff->side);
        self::assertSame(50, $roundOff->paise);
        self::assertSame(100000, $voucher->entries[0]->paise);
    }

    public function test_round_off_is_omitted_when_already_whole(): void
    {
        $voucher = $this->invoice()->addLine('Sales', 1000)->roundOff()->build();

        self::assertCount(2, $voucher->entries);
    }

    public function test_matching_party_total_is_accepted(): void
    {
        $voucher = $this->invoice()
            ->party('Kaveri Traders', '1180.00')
            ->addLine('Sales', 1000)
            ->addTax('CGST', 90)
            ->addTax('SGST', 90)
            ->build();

        self::assertSame(118000, $voucher->total(Side::Debit));
        self::assertSame(118000, $voucher->total(Side::Credit));
    }

    public function test_party_total_mismatch_throws_a_clear_unbalanced_exception(): void
    {
        $builder = $this->invoice()
            ->party('Kaveri Traders', 1200)
            ->addLine('Sales', 1000)
            ->addTax('CGST', 90)
            ->addTax('SGST', 90);

        try {
            $builder->build();
            self::fail('Expected UnbalancedVoucherException');
        } catch (UnbalancedVoucherException $e) {
            self::assertSame('INV-001', $e->voucherNumber);
            self::assertSame(120000, $e->debitPaise);
            self::assertSame(118000, $e->creditPaise);
            self::assertStringContainsString('debits 1200.00, credits 1180.00 (difference 20.00)', $e->getMessage());
            self::assertStringContainsString('Party total 1200.00 does not match', $e->getMessage());
        }
    }

    public function test_discount_is_debited_and_reduces_party_total(): void
    {
        $xml = $this->invoice()
            ->addLine('Sales', 1000)
            ->addDiscount('Discount Allowed', 100)
            ->toXml();

        $this->assertBalancedVouchers($xml);
        self::assertSame([
            'Kaveri Traders' => '-900.00',
            'Sales' => '1000.00',
            'Discount Allowed' => '-100.00',
        ], $this->entryAmounts($xml));
    }

    public function test_bill_wise_adds_a_new_ref_allocation(): void
    {
        $xml = $this->invoice()->addLine('Sales', 500)->billWise()->toXml();

        self::assertSame('INV-001', $this->xpathValue($xml, '//BILLALLOCATIONS.LIST/NAME'));
        self::assertSame('New Ref', $this->xpathValue($xml, '//BILLALLOCATIONS.LIST/BILLTYPE'));
        self::assertSame('-500.00', $this->xpathValue($xml, '//BILLALLOCATIONS.LIST/AMOUNT'));
    }

    public function test_special_characters_are_escaped(): void
    {
        $xml = $this->invoice()
            ->party('A & B "Sons" <Traders>')
            ->narration("Line 1\nLine 2 \x07bell & <tag>")
            ->addLine("Sales 'Local'", 10)
            ->toXml();

        $this->assertBalancedVouchers($xml);
        self::assertStringContainsString('<PARTYLEDGERNAME>A &amp; B &quot;Sons&quot; &lt;Traders&gt;</PARTYLEDGERNAME>', $xml);
        self::assertSame('A & B "Sons" <Traders>', $this->xpathValue($xml, '//PARTYLEDGERNAME'));
        self::assertSame("Line 1\nLine 2 bell & <tag>", $this->xpathValue($xml, '//NARRATION'));
        self::assertStringNotContainsString("\x07", $xml);
    }

    public function test_custom_voucher_type_and_metadata(): void
    {
        $xml = $this->invoice()
            ->voucherType('GST Sales')
            ->reference('PO-77')
            ->partyGstin('33abcde1234f1z7')
            ->placeOfSupply('Tamil Nadu')
            ->addLine('Sales', 10)
            ->toXml('My Company');

        self::assertSame('GST Sales', $this->xpathValue($xml, '//VOUCHER/@VCHTYPE'));
        self::assertSame('GST Sales', $this->xpathValue($xml, '//VOUCHERTYPENAME'));
        self::assertSame('PO-77', $this->xpathValue($xml, '//REFERENCE'));
        self::assertSame('33ABCDE1234F1Z7', $this->xpathValue($xml, '//VOUCHER/PARTYGSTIN'));
        self::assertSame('Tamil Nadu', $this->xpathValue($xml, '//PLACEOFSUPPLY'));
        self::assertSame('My Company', $this->xpathValue($xml, '//SVCURRENTCOMPANY'));
    }

    public function test_invalid_party_gstin_is_rejected(): void
    {
        $this->expectException(InvalidGstinException::class);
        $this->invoice()->partyGstin('33ABCDE1234F1Z8');
    }

    public function test_negative_line_points_to_add_discount(): void
    {
        $this->expectException(InvalidVoucherException::class);
        $this->expectExceptionMessage('Use addDiscount()');
        $this->invoice()->addLine('Sales', -10);
    }

    public function test_requires_party_lines_number_and_date(): void
    {
        $cases = [
            'party' => fn () => TallyVoucher::sales()->number('1')->date('2026-04-01')->addLine('Sales', 1)->build(),
            'addLine' => fn () => TallyVoucher::sales()->number('1')->date('2026-04-01')->party('P')->build(),
            'number' => fn () => TallyVoucher::sales()->date('2026-04-01')->party('P')->addLine('Sales', 1)->build(),
            'date' => fn () => TallyVoucher::sales()->number('1')->party('P')->addLine('Sales', 1)->build(),
        ];

        foreach ($cases as $missing => $build) {
            try {
                $build();
                self::fail("Expected an exception when {$missing} is missing");
            } catch (InvalidVoucherException $e) {
                self::assertStringContainsString($missing, $e->getMessage());
            }
        }
    }

    public function test_apply_gst_without_rates_is_an_error(): void
    {
        $this->expectExceptionMessage('no line has a gstRate');
        $this->invoice()->addLine('Sales', 100)->applyGst(false)->build();
    }

    public function test_discounts_larger_than_lines_are_rejected(): void
    {
        $this->expectExceptionMessage('non-positive total');
        $this->invoice()->addLine('Sales', 100)->addDiscount('Discount', 150)->build();
    }

    public function test_discount_with_gst_rate_reduces_the_taxable_value(): void
    {
        // CGST Act s.15(3)(a): tax is charged on 1000 - 100 = 900, not on 1000.
        $xml = $this->invoice()
            ->addLine('Sales @ 18%', 1000, gstRate: 18)
            ->addDiscount('Discount Allowed', 100, gstRate: 18)
            ->applyGst(interState: false)
            ->toXml();

        $this->assertBalancedVouchers($xml);
        self::assertSame([
            'Kaveri Traders' => '-1062.00',
            'Sales @ 18%' => '1000.00',
            'CGST' => '81.00',
            'SGST' => '81.00',
            'Discount Allowed' => '-100.00',
        ], $this->entryAmounts($xml));
    }

    public function test_discount_only_reduces_the_taxable_value_of_its_own_rate(): void
    {
        $xml = $this->invoice()
            ->addLine('Sales @ 18%', 1000, gstRate: 18)
            ->addLine('Sales @ 5%', 400, gstRate: 5)
            ->addDiscount('Discount Allowed', 200, gstRate: 5)
            ->applyGst(interState: true, ledgers: new GstLedgers(igst: 'IGST {rate}%'))
            ->toXml();

        $this->assertBalancedVouchers($xml);
        self::assertSame([
            'Kaveri Traders' => '-1390.00',
            'Sales @ 18%' => '1000.00',
            'Sales @ 5%' => '400.00',
            'IGST 18%' => '180.00',
            'IGST 5%' => '10.00',
            'Discount Allowed' => '-200.00',
        ], $this->entryAmounts($xml));
    }

    public function test_discount_on_nil_rated_lines_does_not_change_tax(): void
    {
        $xml = $this->invoice()
            ->addLine('Sales @ 18%', 1000, gstRate: 18)
            ->addLine('Sales Exempt', 500, gstRate: 0)
            ->addDiscount('Discount Allowed', 50, gstRate: 0)
            ->applyGst(interState: true)
            ->toXml();

        $this->assertBalancedVouchers($xml);
        self::assertSame('180.00', $this->entryAmounts($xml)['IGST']);
        self::assertSame('-1630.00', $this->entryAmounts($xml)['Kaveri Traders']);
    }

    public function test_discount_without_gst_rate_is_rejected_when_gst_is_computed(): void
    {
        $this->expectException(InvalidVoucherException::class);
        $this->expectExceptionMessage('Discount "Discount Allowed" on "INV-001" needs a gstRate');

        $this->invoice()
            ->addLine('Sales', 1000, gstRate: 18)
            ->addDiscount('Discount Allowed', 100)
            ->applyGst(interState: false)
            ->build();
    }

    public function test_discount_at_a_rate_without_lines_is_rejected(): void
    {
        $this->expectException(InvalidVoucherException::class);
        $this->expectExceptionMessage('is at 12% GST but no line has that gstRate');

        $this->invoice()
            ->addLine('Sales', 1000, gstRate: 18)
            ->addDiscount('Discount Allowed', 100, gstRate: 12)
            ->applyGst(interState: false)
            ->build();
    }

    public function test_discount_larger_than_the_taxable_value_at_its_rate_is_rejected(): void
    {
        $this->expectException(InvalidVoucherException::class);
        $this->expectExceptionMessage('Discounts at 5% GST on "INV-001" exceed the taxable value');

        $this->invoice()
            ->addLine('Sales @ 18%', 1000, gstRate: 18)
            ->addLine('Sales @ 5%', 100, gstRate: 5)
            ->addDiscount('Discount Allowed', 150, gstRate: 5)
            ->applyGst(interState: false)
            ->build();
    }
}
