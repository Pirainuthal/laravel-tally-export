<?php

declare(strict_types=1);

namespace Pirainuthal\TallyExport\Xml;

use Pirainuthal\TallyExport\Contracts\TallyMessage;
use Pirainuthal\TallyExport\Exceptions\InvalidVoucherException;
use XMLWriter;

/**
 * Writes the standard Tally import envelope:
 *
 * ENVELOPE > HEADER > TALLYREQUEST "Import Data"
 *          > BODY > IMPORTDATA > REQUESTDESC (REPORTNAME, STATICVARIABLES)
 *                              > REQUESTDATA > TALLYMESSAGE* > VOUCHER | LEDGER
 */
final class EnvelopeWriter
{
    public function __construct(
        private readonly ?string $company = null,
        private readonly bool $indent = true,
    ) {}

    /**
     * @param  iterable<TallyMessage>  $messages  All messages must share one report name.
     */
    public function write(iterable $messages, ?string $reportName = null): string
    {
        $messages = is_array($messages) ? array_values($messages) : iterator_to_array($messages, false);
        $reportName ??= ($messages[0] ?? null)?->reportName() ?? 'Vouchers';

        foreach ($messages as $message) {
            if ($message->reportName() !== $reportName) {
                throw new InvalidVoucherException(sprintf(
                    'Cannot mix "%s" and "%s" in one envelope; export masters and vouchers separately.',
                    $reportName,
                    $message->reportName(),
                ));
            }
        }

        $xml = new XMLWriter;
        $xml->openMemory();
        $xml->setIndent($this->indent);
        $xml->setIndentString('  ');

        $xml->startElement('ENVELOPE');

        $xml->startElement('HEADER');
        $xml->writeElement('TALLYREQUEST', 'Import Data');
        $xml->endElement();

        $xml->startElement('BODY');
        $xml->startElement('IMPORTDATA');

        $xml->startElement('REQUESTDESC');
        $xml->writeElement('REPORTNAME', $reportName);
        if ($this->company !== null && $this->company !== '') {
            $xml->startElement('STATICVARIABLES');
            $xml->writeElement('SVCURRENTCOMPANY', Text::clean($this->company));
            $xml->endElement();
        }
        $xml->endElement();

        $xml->startElement('REQUESTDATA');
        foreach ($messages as $message) {
            $xml->startElement('TALLYMESSAGE');
            $xml->writeAttribute('xmlns:UDF', 'TallyUDF');
            $message->writeXml($xml);
            $xml->endElement();
        }
        $xml->endElement();

        $xml->endElement(); // IMPORTDATA
        $xml->endElement(); // BODY
        $xml->endElement(); // ENVELOPE

        return $xml->outputMemory();
    }
}
