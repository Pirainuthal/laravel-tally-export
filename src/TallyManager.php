<?php

declare(strict_types=1);

namespace Pirainuthal\TallyExport;

use Illuminate\Database\Eloquent\Model;
use Pirainuthal\TallyExport\Builders\JournalVoucherBuilder;
use Pirainuthal\TallyExport\Builders\LedgerBuilder;
use Pirainuthal\TallyExport\Builders\ReceiptVoucherBuilder;
use Pirainuthal\TallyExport\Builders\SalesVoucherBuilder;
use Pirainuthal\TallyExport\Builders\VoucherBuilder;
use Pirainuthal\TallyExport\Contracts\TallyMessage;
use Pirainuthal\TallyExport\Contracts\VoucherMapper;
use Pirainuthal\TallyExport\Data\Ledger;
use Pirainuthal\TallyExport\Data\Voucher;
use Pirainuthal\TallyExport\Http\ImportResult;
use Pirainuthal\TallyExport\Http\TallyClient;
use Pirainuthal\TallyExport\Xml\EnvelopeWriter;

/**
 * The service behind the Tally facade.
 */
final class TallyManager
{
    public function __construct(
        private readonly TallyExporter $exporter,
        private readonly EnvelopeWriter $writer,
        private readonly TallyClient $client,
    ) {}

    public function sales(): SalesVoucherBuilder
    {
        return TallyVoucher::sales();
    }

    public function receipt(): ReceiptVoucherBuilder
    {
        return TallyVoucher::receipt();
    }

    public function journal(): JournalVoucherBuilder
    {
        return TallyVoucher::journal();
    }

    public function ledger(string $name, string $parent = ''): LedgerBuilder
    {
        return TallyLedger::make($name, $parent);
    }

    /**
     * @param  iterable<mixed>  $items
     * @param  VoucherMapper<Model>|(callable(mixed): (Voucher|VoucherBuilder|array<string, mixed>|null))|null  $mapper
     */
    public function export(iterable $items, VoucherMapper|callable|null $mapper = null): string
    {
        return $this->exporter->export($items, $mapper);
    }

    /**
     * @param  iterable<Ledger|LedgerBuilder>  $ledgers
     */
    public function exportLedgers(iterable $ledgers): string
    {
        return $this->exporter->exportLedgers($ledgers);
    }

    /**
     * Sends XML (or a voucher / ledger / builder) to Tally and parses the response.
     */
    public function push(string|TallyMessage|VoucherBuilder|LedgerBuilder $payload): ImportResult
    {
        if ($payload instanceof VoucherBuilder || $payload instanceof LedgerBuilder) {
            $payload = $payload->build();
        }

        if ($payload instanceof TallyMessage) {
            $payload = $this->writer->write([$payload]);
        }

        return $this->client->push($payload);
    }

    public function exporter(): TallyExporter
    {
        return $this->exporter;
    }

    public function writer(): EnvelopeWriter
    {
        return $this->writer;
    }

    public function client(): TallyClient
    {
        return $this->client;
    }
}
