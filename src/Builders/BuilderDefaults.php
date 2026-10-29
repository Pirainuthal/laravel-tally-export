<?php

declare(strict_types=1);

namespace Pirainuthal\TallyExport\Builders;

use Pirainuthal\TallyExport\Gst\GstLedgers;

/**
 * Ledger names the builders fall back to. The service provider fills this
 * from config/tally.php; outside Laravel the constructor defaults apply.
 */
final class BuilderDefaults
{
    public function __construct(
        public readonly GstLedgers $gstLedgers = new GstLedgers,
        public readonly string $roundOffLedger = 'Round Off',
        public readonly string $salesVoucherType = 'Sales',
        public readonly string $receiptVoucherType = 'Receipt',
        public readonly string $journalVoucherType = 'Journal',
    ) {}

    /**
     * @param  array<string, mixed>  $config  the "tally" config array
     */
    public static function fromConfig(array $config): self
    {
        $ledgers = is_array($config['ledgers'] ?? null) ? $config['ledgers'] : [];
        $types = is_array($config['voucher_types'] ?? null) ? $config['voucher_types'] : [];

        $pick = static function (array $source, string $key, string $default): string {
            $value = $source[$key] ?? null;

            return is_string($value) && $value !== '' ? $value : $default;
        };

        return new self(
            gstLedgers: GstLedgers::fromArray($ledgers),
            roundOffLedger: $pick($ledgers, 'round_off', 'Round Off'),
            salesVoucherType: $pick($types, 'sales', 'Sales'),
            receiptVoucherType: $pick($types, 'receipt', 'Receipt'),
            journalVoucherType: $pick($types, 'journal', 'Journal'),
        );
    }
}
