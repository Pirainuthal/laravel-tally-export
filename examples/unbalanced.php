<?php

declare(strict_types=1);

// php examples/unbalanced.php
require __DIR__.'/../vendor/autoload.php';

use Pirainuthal\TallyExport\Exceptions\UnbalancedVoucherException;
use Pirainuthal\TallyExport\TallyVoucher;

try {
    TallyVoucher::sales()
        ->number('INV-2026-0043')
        ->date('2026-04-16')
        ->party('Kaveri Traders', total: 1200)  // the invoice says 1200...
        ->addLine('Sales @ 18%', 1000)
        ->addTax('CGST', 90)
        ->addTax('SGST', 90)                    // ...but lines + tax = 1180
        ->build();
} catch (UnbalancedVoucherException $e) {
    echo get_class($e), ': ', $e->getMessage(), PHP_EOL;
}

try {
    TallyVoucher::journal()
        ->number('JV-7')
        ->date('2026-06-30')
        ->debit('Rent', 25000)
        ->credit('State Bank Current A/c', '24999.99')
        ->build();
} catch (UnbalancedVoucherException $e) {
    echo get_class($e), ': ', $e->getMessage(), PHP_EOL;
}
