<?php

declare(strict_types=1);

namespace Pirainuthal\TallyExport\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Pirainuthal\TallyExport\Builders\BuilderDefaults;
use Pirainuthal\TallyExport\Exceptions\InvalidVoucherException;
use Pirainuthal\TallyExport\Exceptions\UnbalancedVoucherException;
use Pirainuthal\TallyExport\Gst\GstLedgers;
use Pirainuthal\TallyExport\TallyExporter;
use Pirainuthal\TallyExport\TallyVoucher;
use Pirainuthal\TallyExport\Tests\Concerns\AssertsTallyXml;
use Pirainuthal\TallyExport\VoucherFactory;
use stdClass;

final class ExporterAndFactoryTest extends TestCase
{
    use AssertsTallyXml;

    protected function setUp(): void
    {
        TallyVoucher::useDefaults(null);
    }

    public function test_sales_from_array(): void
    {
        $voucher = TallyVoucher::fromArray([
            'type' => 'sales',
            'number' => 'INV-9',
            'date' => '2026-07-01',
            'party' => 'Kaveri Traders',
            'party_total' => '2360',
            'lines' => [['ledger' => 'Sales', 'amount' => 2000, 'gst_rate' => 18]],
            'gst' => 'inter',
            'round_off' => true,
            'bill_wise' => true,
            'narration' => 'From array',
        ]);

        self::assertSame('INV-9', $voucher->number);
        self::assertSame(['Kaveri Traders', 'Sales', 'IGST'], array_map(static fn ($e) => $e->ledgerName, $voucher->entries));
        self::assertSame(0, $voucher->signedSum());
    }

    public function test_sales_from_array_with_discount_at_a_gst_rate(): void
    {
        $voucher = TallyVoucher::fromArray([
            'number' => 'INV-10',
            'date' => '2026-07-01',
            'party' => 'Kaveri Traders',
            'party_total' => 2124,
            'lines' => [['ledger' => 'Sales', 'amount' => 2000, 'gst_rate' => 18]],
            'discounts' => [['ledger' => 'Discount Allowed', 'amount' => 200, 'gst_rate' => 18]],
            'gst' => 'inter',
        ]);

        $amounts = [];
        foreach ($voucher->entries as $entry) {
            $amounts[$entry->ledgerName] = $entry->paise;
        }

        // IGST 18% on 2000 - 200 = 1800 is 324; 2000 + 324 - 200 = 2124.
        self::assertSame(['Kaveri Traders' => 212400, 'Sales' => 200000, 'IGST' => 32400, 'Discount Allowed' => 20000], $amounts);
    }

    public function test_receipt_and_journal_from_array(): void
    {
        $receipt = TallyVoucher::fromArray([
            'type' => 'receipt', 'number' => 7, 'date' => '2026-07-02',
            'party' => 'Kaveri Traders', 'amount' => 2360, 'deposit_to' => 'Bank',
            'against' => [['ref' => 'INV-9']],
        ]);
        self::assertSame('7', $receipt->number);

        $journal = TallyVoucher::fromArray([
            'type' => 'journal', 'number' => 'J1', 'date' => '2026-07-03', 'voucher_type' => 'Contra',
            'debits' => [['ledger' => 'Cash', 'amount' => 500]],
            'credits' => [['ledger' => 'Bank', 'amount' => 500]],
        ]);
        self::assertSame('Contra', $journal->voucherType);
    }

    public function test_array_errors_are_explicit(): void
    {
        $factory = new VoucherFactory;

        foreach ([
            [['type' => 'payroll'], 'Unknown voucher type'],
            [['number' => 'X', 'date' => '2026-01-01', 'party' => 'P', 'lines' => 'nope'], '"lines" must be a list'],
            [['number' => 'X', 'date' => '2026-01-01', 'party' => 'P', 'lines' => [['ledger' => 'S']]], 'Missing "amount"'],
            [['date' => '2026-01-01'], 'needs a "number"'],
            [['number' => 'X', 'date' => '2026-01-01', 'party' => 'P', 'lines' => [['ledger' => 'S', 'amount' => 1, 'gst_rate' => 5]], 'gst' => 'maybe'], '"gst" must be'],
        ] as [$data, $message]) {
            try {
                $factory->fromArray($data);
                self::fail('Expected exception: '.$message);
            } catch (InvalidVoucherException $e) {
                self::assertStringContainsString($message, $e->getMessage());
            }
        }
    }

    public function test_array_party_total_mismatch_is_unbalanced(): void
    {
        $this->expectException(UnbalancedVoucherException::class);

        TallyVoucher::fromArray([
            'number' => 'INV-10', 'date' => '2026-07-01', 'party' => 'P', 'party_total' => 100,
            'lines' => [['ledger' => 'Sales', 'amount' => 99]],
        ]);
    }

    public function test_export_mixes_vouchers_builders_arrays_and_callable_mapper(): void
    {
        $exporter = new TallyExporter;
        $journal = TallyVoucher::journal()->number('J1')->date('2026-04-01')->debit('A', 1)->credit('B', 1);

        $xml = $exporter->export([
            $journal,
            $journal->build(),
            ['type' => 'journal', 'number' => 'J2', 'date' => '2026-04-01', 'debits' => [['ledger' => 'A', 'amount' => 2]], 'credits' => [['ledger' => 'B', 'amount' => 2]]],
        ]);
        self::assertSame(3, $this->countElements($xml, '//VOUCHER'));

        $mapped = $exporter->vouchers(
            [['n' => 'X1', 'skip' => false], ['n' => 'X2', 'skip' => true]],
            static fn (array $row) => $row['skip'] ? null
                : TallyVoucher::journal()->number($row['n'])->date('2026-04-01')->debit('A', 1)->credit('B', 1),
        );
        self::assertCount(1, $mapped);
        self::assertSame('X1', $mapped[0]->number);
    }

    public function test_export_rejects_unknown_items(): void
    {
        $this->expectExceptionMessage('stdClass cannot be exported');
        (new TallyExporter)->export([new stdClass]);
    }

    public function test_builder_defaults_from_config(): void
    {
        $defaults = BuilderDefaults::fromConfig([
            'ledgers' => ['cgst' => 'Out CGST {rate}%', 'sgst' => 'Out SGST {rate}%', 'igst' => '', 'round_off' => 'Rounding'],
            'voucher_types' => ['sales' => 'GST Sales'],
        ]);

        self::assertEquals(new GstLedgers('Out CGST {rate}%', 'Out SGST {rate}%', 'IGST'), $defaults->gstLedgers);
        self::assertSame('Rounding', $defaults->roundOffLedger);
        self::assertSame('GST Sales', $defaults->salesVoucherType);
        self::assertSame('Receipt', $defaults->receiptVoucherType);
    }
}
