<?php

declare(strict_types=1);

namespace Pirainuthal\TallyExport;

use Pirainuthal\TallyExport\Builders\BuilderDefaults;
use Pirainuthal\TallyExport\Builders\JournalVoucherBuilder;
use Pirainuthal\TallyExport\Builders\ReceiptVoucherBuilder;
use Pirainuthal\TallyExport\Builders\SalesVoucherBuilder;
use Pirainuthal\TallyExport\Data\Voucher;

/**
 * Entry point for voucher builders.
 *
 * Inside Laravel the default ledger names come from config/tally.php
 * (the service provider calls useDefaults()); elsewhere the built-in defaults apply.
 */
final class TallyVoucher
{
    private static ?BuilderDefaults $defaults = null;

    private function __construct() {}

    public static function sales(): SalesVoucherBuilder
    {
        return new SalesVoucherBuilder(self::defaults());
    }

    public static function receipt(): ReceiptVoucherBuilder
    {
        return new ReceiptVoucherBuilder(self::defaults());
    }

    public static function journal(): JournalVoucherBuilder
    {
        return new JournalVoucherBuilder(self::defaults());
    }

    /**
     * Builds a voucher from a plain array (see README "Array format").
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): Voucher
    {
        return (new VoucherFactory(self::defaults()))->fromArray($data);
    }

    public static function useDefaults(?BuilderDefaults $defaults): void
    {
        self::$defaults = $defaults;
    }

    public static function defaults(): BuilderDefaults
    {
        return self::$defaults ??= new BuilderDefaults;
    }
}
