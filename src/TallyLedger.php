<?php

declare(strict_types=1);

namespace Pirainuthal\TallyExport;

use Pirainuthal\TallyExport\Builders\LedgerBuilder;
use Pirainuthal\TallyExport\Gst\GstDutyHead;

/**
 * Entry point for ledger master builders, pre-filled with Tally's default groups.
 */
final class TallyLedger
{
    private function __construct() {}

    public static function make(string $name, string $parent = ''): LedgerBuilder
    {
        return new LedgerBuilder($name, $parent);
    }

    /** Customer under Sundry Debtors with bill-wise details on. */
    public static function customer(string $name): LedgerBuilder
    {
        return (new LedgerBuilder($name, 'Sundry Debtors'))->billWise();
    }

    /** Supplier under Sundry Creditors with bill-wise details on. */
    public static function supplier(string $name): LedgerBuilder
    {
        return (new LedgerBuilder($name, 'Sundry Creditors'))->billWise();
    }

    public static function sales(string $name): LedgerBuilder
    {
        return new LedgerBuilder($name, 'Sales Accounts');
    }

    public static function bank(string $name): LedgerBuilder
    {
        return new LedgerBuilder($name, 'Bank Accounts');
    }

    /** CGST / SGST / IGST / Cess ledger under Duties & Taxes. */
    public static function gstDuty(string $name, GstDutyHead $head): LedgerBuilder
    {
        return (new LedgerBuilder($name, 'Duties & Taxes'))->dutyHead($head);
    }

    /** Round-off ledger; Indirect Expenses is the usual choice, change it with under(). */
    public static function roundOff(string $name = 'Round Off'): LedgerBuilder
    {
        return new LedgerBuilder($name, 'Indirect Expenses');
    }
}
