<?php

declare(strict_types=1);

namespace Pirainuthal\TallyExport\Facades;

use Illuminate\Support\Facades\Facade;
use Pirainuthal\TallyExport\TallyManager;

/**
 * @method static \Pirainuthal\TallyExport\Builders\SalesVoucherBuilder sales()
 * @method static \Pirainuthal\TallyExport\Builders\ReceiptVoucherBuilder receipt()
 * @method static \Pirainuthal\TallyExport\Builders\JournalVoucherBuilder journal()
 * @method static \Pirainuthal\TallyExport\Builders\LedgerBuilder ledger(string $name, string $parent = '')
 * @method static string export(iterable<mixed> $items, \Pirainuthal\TallyExport\Contracts\VoucherMapper<\Illuminate\Database\Eloquent\Model>|callable|null $mapper = null)
 * @method static string exportLedgers(iterable<\Pirainuthal\TallyExport\Data\Ledger|\Pirainuthal\TallyExport\Builders\LedgerBuilder> $ledgers)
 * @method static \Pirainuthal\TallyExport\Http\ImportResult push(string|\Pirainuthal\TallyExport\Contracts\TallyMessage|\Pirainuthal\TallyExport\Builders\VoucherBuilder|\Pirainuthal\TallyExport\Builders\LedgerBuilder $payload)
 * @method static \Pirainuthal\TallyExport\TallyExporter exporter()
 * @method static \Pirainuthal\TallyExport\Xml\EnvelopeWriter writer()
 * @method static \Pirainuthal\TallyExport\Http\TallyClient client()
 *
 * @see TallyManager
 */
final class Tally extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return TallyManager::class;
    }
}
