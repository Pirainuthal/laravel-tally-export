<?php

declare(strict_types=1);

namespace Pirainuthal\TallyExport;

use Illuminate\Database\Eloquent\Model;
use Pirainuthal\TallyExport\Builders\LedgerBuilder;
use Pirainuthal\TallyExport\Builders\VoucherBuilder;
use Pirainuthal\TallyExport\Contracts\VoucherMapper;
use Pirainuthal\TallyExport\Data\Ledger;
use Pirainuthal\TallyExport\Data\Voucher;
use Pirainuthal\TallyExport\Exceptions\InvalidVoucherException;
use Pirainuthal\TallyExport\Xml\EnvelopeWriter;

/**
 * Turns a batch of invoices / receipts (or ledger masters) into one Tally envelope.
 */
final class TallyExporter
{
    public function __construct(
        private readonly EnvelopeWriter $writer = new EnvelopeWriter,
        private readonly VoucherFactory $factory = new VoucherFactory,
    ) {}

    /**
     * @param  iterable<mixed>  $items  Voucher, VoucherBuilder, array, or anything $mapper understands
     * @param  VoucherMapper<Model>|(callable(mixed): (Voucher|VoucherBuilder|array<string, mixed>|null))|null  $mapper
     */
    public function export(iterable $items, VoucherMapper|callable|null $mapper = null): string
    {
        return $this->writer->write($this->vouchers($items, $mapper), 'Vouchers');
    }

    /**
     * Normalises every item into a validated Voucher (null results are skipped).
     *
     * @param  iterable<mixed>  $items
     * @param  VoucherMapper<Model>|(callable(mixed): (Voucher|VoucherBuilder|array<string, mixed>|null))|null  $mapper
     * @return list<Voucher>
     */
    public function vouchers(iterable $items, VoucherMapper|callable|null $mapper = null): array
    {
        $vouchers = [];

        foreach ($items as $index => $item) {
            if ($mapper instanceof VoucherMapper) {
                if (! $item instanceof Model) {
                    throw new InvalidVoucherException(sprintf('Item %s is not an Eloquent model; VoucherMapper needs models.', (string) $index));
                }
                $item = $mapper->map($item);
            } elseif ($mapper !== null) {
                $item = $mapper($item);
            }

            if ($item === null) {
                continue;
            }

            $vouchers[] = $this->toVoucher($item, $index);
        }

        return $vouchers;
    }

    /**
     * @param  iterable<Ledger|LedgerBuilder>  $ledgers
     */
    public function exportLedgers(iterable $ledgers): string
    {
        $built = [];
        foreach ($ledgers as $ledger) {
            $built[] = $ledger instanceof LedgerBuilder ? $ledger->build() : $ledger;
        }

        return $this->writer->write($built, 'All Masters');
    }

    private function toVoucher(mixed $item, int|string $index): Voucher
    {
        return match (true) {
            $item instanceof Voucher => $item,
            $item instanceof VoucherBuilder => $item->build(),
            is_array($item) => $this->factory->fromArray($item),
            default => throw new InvalidVoucherException(sprintf(
                'Item %s of type %s cannot be exported; pass a Voucher, a builder, an array or a mapper.',
                (string) $index,
                get_debug_type($item),
            )),
        };
    }
}
