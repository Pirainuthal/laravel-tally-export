<?php

declare(strict_types=1);

// php examples/basic.php
require __DIR__.'/../vendor/autoload.php';

use Pirainuthal\TallyExport\TallyVoucher;

echo TallyVoucher::sales()
    ->number('INV-2026-0042')
    ->date('2026-04-15')
    ->party('Kaveri Traders')
    ->narration('Being goods sold')
    ->addLine('Sales @ 18%', '1234.50', gstRate: 18)
    ->applyGst(interState: false)   // CGST 9% + SGST 9%
    ->roundOff()                    // to the nearest rupee
    ->billWise()                    // "New Ref" INV-2026-0042
    ->toXml(), PHP_EOL;
