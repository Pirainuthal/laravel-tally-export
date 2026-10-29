<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Tally HTTP server
    |--------------------------------------------------------------------------
    |
    | Used by Tally::push() and `php artisan tally:export --push`. Enable it in
    | Tally Prime under F1 (Help) > Settings > Connectivity ("TallyPrime acts as
    | Server", port 9000 by default).
    |
    */

    'url' => env('TALLY_URL', 'http://localhost:9000'),

    'timeout' => (int) env('TALLY_TIMEOUT', 30),

    /*
    | Company to import into (SVCURRENTCOMPANY). Leave null to use the company
    | currently open in Tally.
    */

    'company' => env('TALLY_COMPANY'),

    /*
    |--------------------------------------------------------------------------
    | Ledger and voucher type names
    |--------------------------------------------------------------------------
    |
    | These must match ledgers that already exist in your Tally company.
    | "{rate}" is replaced with the rate of that tax, so with
    | 'cgst' => 'Output CGST {rate}%' an 18% line posts to "Output CGST 9%".
    |
    */

    'ledgers' => [
        'cgst' => env('TALLY_LEDGER_CGST', 'CGST'),
        'sgst' => env('TALLY_LEDGER_SGST', 'SGST'),
        'igst' => env('TALLY_LEDGER_IGST', 'IGST'),
        'round_off' => env('TALLY_LEDGER_ROUND_OFF', 'Round Off'),
    ],

    'voucher_types' => [
        'sales' => 'Sales',
        'receipt' => 'Receipt',
        'journal' => 'Journal',
    ],

    /*
    |--------------------------------------------------------------------------
    | tally:export command
    |--------------------------------------------------------------------------
    |
    | model:       Eloquent model holding your invoices, e.g. App\Models\Invoice::class
    | mapper:      class implementing Pirainuthal\TallyExport\Contracts\VoucherMapper
    | date_column: column filtered by --from / --to
    | output:      default file; {from} and {to} are replaced (or "all")
    |
    */

    'export' => [
        'model' => null,
        'mapper' => null,
        'date_column' => 'invoice_date',
        'chunk' => 500,
        'output' => 'tally/vouchers-{from}-{to}.xml',
    ],

];
