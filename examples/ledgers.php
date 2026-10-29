<?php

declare(strict_types=1);

// php examples/ledgers.php
require __DIR__.'/../vendor/autoload.php';

use Pirainuthal\TallyExport\Gst\GstDutyHead;
use Pirainuthal\TallyExport\Gst\Gstin;
use Pirainuthal\TallyExport\TallyExporter;
use Pirainuthal\TallyExport\TallyLedger;

var_dump(Gstin::isValid('33ABCDE1234F1Z7'), Gstin::isValid('33ABCDE1234F1Z8'));
echo Gstin::parse('33ABCDE1234F1Z7')->stateName(), PHP_EOL;

echo (new TallyExporter)->exportLedgers([
    TallyLedger::customer('Kaveri Traders')
        ->gstin('33ABCDE1234F1Z7')
        ->stateFromGstin()
        ->address('12, Gandhi Road', 'Erode')
        ->pincode('638001')
        ->openingBalance(5000),          // Dr 5000 -> -5000.00
    TallyLedger::gstDuty('CGST', GstDutyHead::Central),
]), PHP_EOL;
