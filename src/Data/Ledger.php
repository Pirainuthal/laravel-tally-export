<?php

declare(strict_types=1);

namespace Pirainuthal\TallyExport\Data;

use Pirainuthal\TallyExport\Contracts\TallyMessage;
use Pirainuthal\TallyExport\Exceptions\InvalidVoucherException;
use Pirainuthal\TallyExport\Gst\GstDutyHead;
use Pirainuthal\TallyExport\Gst\Gstin;
use Pirainuthal\TallyExport\Gst\GstRegistrationType;
use Pirainuthal\TallyExport\Support\Amount;
use Pirainuthal\TallyExport\Support\Side;
use Pirainuthal\TallyExport\Xml\Text;
use XMLWriter;

/**
 * An immutable ledger master (customer, sales account, duty ledger, bank...).
 */
final class Ledger implements TallyMessage
{
    /**
     * @param  list<string>  $address
     */
    public function __construct(
        public readonly string $name,
        public readonly string $parent,
        public readonly ?bool $billWise = null,
        public readonly array $address = [],
        public readonly ?string $state = null,
        public readonly ?string $country = null,
        public readonly ?string $pincode = null,
        public readonly ?string $email = null,
        public readonly ?Gstin $gstin = null,
        public readonly ?GstRegistrationType $registrationType = null,
        public readonly ?GstDutyHead $dutyHead = null,
        public readonly int $openingPaise = 0,
        public readonly Side $openingSide = Side::Debit,
        public readonly string $action = 'Create',
    ) {
        if (trim($name) === '') {
            throw new InvalidVoucherException('Ledger name must not be empty.');
        }

        if (trim($parent) === '') {
            throw new InvalidVoucherException(sprintf('Ledger "%s" needs a parent group.', $name));
        }

        if (! in_array($action, ['Create', 'Alter'], true)) {
            throw new InvalidVoucherException(sprintf('Ledger action must be "Create" or "Alter", got "%s".', $action));
        }

        if ($openingPaise < 0) {
            throw new InvalidVoucherException('Opening balance must be unsigned; choose the side with Side::Debit or Side::Credit.');
        }
    }

    public function reportName(): string
    {
        return 'All Masters';
    }

    public function writeXml(XMLWriter $xml): void
    {
        $name = Text::clean($this->name);

        $xml->startElement('LEDGER');
        $xml->writeAttribute('NAME', $name);
        $xml->writeAttribute('ACTION', $this->action);

        $xml->startElement('NAME.LIST');
        $xml->writeAttribute('TYPE', 'String');
        $xml->writeElement('NAME', $name);
        $xml->endElement();

        $xml->writeElement('PARENT', Text::clean($this->parent));

        if ($this->address !== []) {
            $xml->startElement('ADDRESS.LIST');
            $xml->writeAttribute('TYPE', 'String');
            foreach ($this->address as $line) {
                $xml->writeElement('ADDRESS', Text::clean($line));
            }
            $xml->endElement();
        }

        if ($this->billWise !== null) {
            $xml->writeElement('ISBILLWISEON', Text::yesNo($this->billWise));
        }

        $this->optional($xml, 'COUNTRYNAME', $this->country);
        $this->optional($xml, 'LEDSTATENAME', $this->state);
        $this->optional($xml, 'PINCODE', $this->pincode);
        $this->optional($xml, 'EMAIL', $this->email);
        $this->optional($xml, 'GSTREGISTRATIONTYPE', $this->registrationType?->value);
        $this->optional($xml, 'PARTYGSTIN', $this->gstin?->value);

        if ($this->dutyHead !== null) {
            $xml->writeElement('TAXTYPE', 'GST');
            $xml->writeElement('GSTDUTYHEAD', $this->dutyHead->value);
        }

        if ($this->openingPaise !== 0) {
            $xml->writeElement('OPENINGBALANCE', Amount::format($this->openingSide->signedForTally($this->openingPaise)));
        }

        $xml->endElement();
    }

    private function optional(XMLWriter $xml, string $tag, ?string $value): void
    {
        if ($value !== null && $value !== '') {
            $xml->writeElement($tag, Text::clean($value));
        }
    }
}
