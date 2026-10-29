<?php

declare(strict_types=1);

namespace Pirainuthal\TallyExport\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Pirainuthal\TallyExport\Data\BillType;
use Pirainuthal\TallyExport\Exceptions\InvalidVoucherException;
use Pirainuthal\TallyExport\Exceptions\UnbalancedVoucherException;
use Pirainuthal\TallyExport\TallyVoucher;
use Pirainuthal\TallyExport\Tests\Concerns\AssertsTallyXml;

final class ReceiptAndJournalTest extends TestCase
{
    use AssertsTallyXml;

    protected function setUp(): void
    {
        TallyVoucher::useDefaults(null);
    }

    public function test_receipt_credits_party_and_debits_bank(): void
    {
        $xml = TallyVoucher::receipt()
            ->number('RCT-1')
            ->date('2026-04-30')
            ->party('Kaveri Traders', 1180)
            ->depositTo('State Bank Current A/c')
            ->againstInvoice('INV-001')
            ->toXml();

        $this->assertBalancedVouchers($xml);
        self::assertSame('Receipt', $this->xpathValue($xml, '//VOUCHER/@VCHTYPE'));
        self::assertSame([
            'Kaveri Traders' => '1180.00',
            'State Bank Current A/c' => '-1180.00',
        ], $this->entryAmounts($xml));
        self::assertSame('Agst Ref', $this->xpathValue($xml, '//BILLALLOCATIONS.LIST/BILLTYPE'));
        self::assertSame('INV-001', $this->xpathValue($xml, '//BILLALLOCATIONS.LIST/NAME'));
        self::assertSame('1180.00', $this->xpathValue($xml, '//BILLALLOCATIONS.LIST/AMOUNT'));
    }

    public function test_receipt_with_tds_deduction_and_partial_allocation(): void
    {
        $voucher = TallyVoucher::receipt()
            ->number('RCT-2')
            ->date('2026-05-02')
            ->party('Kaveri Traders', 5000)
            ->depositTo('Bank')
            ->deduct('TDS Receivable', 100)
            ->againstInvoice('INV-001', 1180)
            ->againstInvoice('INV-002', 2000)
            ->build();

        self::assertSame(0, $voucher->signedSum());
        self::assertSame(490000, $voucher->entries[1]->paise);
        self::assertSame('TDS Receivable', $voucher->entries[2]->ledgerName);

        $bills = $voucher->entries[0]->billAllocations;
        self::assertCount(3, $bills);
        self::assertSame(BillType::OnAccount, $bills[2]->type);
        self::assertSame(182000, $bills[2]->paise);
    }

    public function test_receipt_over_allocation_is_rejected(): void
    {
        $this->expectException(InvalidVoucherException::class);
        $this->expectExceptionMessage('exceed the receipt amount');

        TallyVoucher::receipt()->number('R')->date('2026-04-01')
            ->party('P', 100)->depositTo('Cash')
            ->againstInvoice('A', 80)->againstInvoice('B', 30)
            ->build();
    }

    public function test_receipt_deductions_cannot_swallow_the_amount(): void
    {
        $this->expectExceptionMessage('must be less than the receipt amount');

        TallyVoucher::receipt()->number('R')->date('2026-04-01')
            ->party('P', 100)->depositTo('Cash')->deduct('TDS', 100)->build();
    }

    public function test_receipt_requires_a_bank_or_cash_ledger(): void
    {
        $this->expectExceptionMessage('depositTo()');
        TallyVoucher::receipt()->number('R')->date('2026-04-01')->party('P', 100)->build();
    }

    public function test_balanced_journal(): void
    {
        $xml = TallyVoucher::journal()
            ->number('JV-1')
            ->date('2026-06-30')
            ->debit('Depreciation', '1250.75')
            ->credit('Furniture', '1250.75')
            ->narration('Q1 depreciation')
            ->toXml();

        $this->assertBalancedVouchers($xml);
        self::assertSame('Journal', $this->xpathValue($xml, '//VOUCHER/@VCHTYPE'));
    }

    public function test_unbalanced_journal_throws(): void
    {
        $this->expectException(UnbalancedVoucherException::class);
        $this->expectExceptionMessage('Voucher "JV-2" is not balanced: debits 100.00, credits 99.99 (difference 0.01).');

        TallyVoucher::journal()->number('JV-2')->date('2026-06-30')
            ->debit('Rent', 100)
            ->credit('Cash', '99.99')
            ->build();
    }

    public function test_alter_action_is_written_and_unknown_actions_are_rejected(): void
    {
        $xml = TallyVoucher::journal()->number('JV-4')->date('2026-06-30')
            ->debit('Rent', 1)->credit('Cash', 1)->action('Alter')->toXml();
        self::assertSame('Alter', $this->xpathValue($xml, '//VOUCHER/@ACTION'));

        $this->expectExceptionMessage('must be "Create" or "Alter"');
        TallyVoucher::journal()->number('JV-5')->date('2026-06-30')
            ->debit('Rent', 1)->credit('Cash', 1)->action('Remove')->build();
    }

    public function test_single_entry_voucher_is_rejected(): void
    {
        $this->expectExceptionMessage('at least two ledger entries');
        TallyVoucher::journal()->number('JV-3')->date('2026-06-30')->debit('Rent', 100)->build();
    }
}
