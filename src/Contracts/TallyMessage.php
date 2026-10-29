<?php

declare(strict_types=1);

namespace Pirainuthal\TallyExport\Contracts;

use XMLWriter;

/**
 * Anything that can be written as one TALLYMESSAGE child (a VOUCHER or a LEDGER).
 */
interface TallyMessage
{
    /** The REPORTNAME Tally expects for this kind of object ("Vouchers" or "All Masters"). */
    public function reportName(): string;

    public function writeXml(XMLWriter $xml): void;
}
