<?php

declare(strict_types=1);

namespace Pirainuthal\TallyExport\Data;

use DateTimeImmutable;
use Pirainuthal\TallyExport\Contracts\TallyMessage;
use Pirainuthal\TallyExport\Exceptions\InvalidVoucherException;
use Pirainuthal\TallyExport\Exceptions\UnbalancedVoucherException;
use Pirainuthal\TallyExport\Support\Amount;
use Pirainuthal\TallyExport\Support\Side;
use Pirainuthal\TallyExport\Xml\Text;
use XMLWriter;

/**
 * An immutable, already-validated accounting voucher.
 *
 * Construction fails with UnbalancedVoucherException when total debits
 * differ from total credits, so an instance is always safe to export.
 */
final class Voucher implements TallyMessage
{
    public const VIEW = 'Accounting Voucher View';

    /**
     * @param  list<LedgerEntry>  $entries
     */
    public function __construct(
        public readonly string $voucherType,
        public readonly string $number,
        public readonly DateTimeImmutable $date,
        public readonly array $entries,
        public readonly ?string $partyLedgerName = null,
        public readonly ?string $narration = null,
        public readonly ?string $reference = null,
        public readonly ?string $partyGstin = null,
        public readonly ?string $placeOfSupply = null,
        public readonly string $action = 'Create',
    ) {
        if (trim($voucherType) === '') {
            throw new InvalidVoucherException('Voucher type must not be empty.');
        }

        if (trim($number) === '') {
            throw new InvalidVoucherException(sprintf('%s voucher needs a voucher number.', $voucherType));
        }

        if (! in_array($action, ['Create', 'Alter'], true)) {
            throw new InvalidVoucherException(sprintf('Voucher action must be "Create" or "Alter", got "%s".', $action));
        }

        if (count($entries) < 2) {
            throw new InvalidVoucherException(sprintf(
                'Voucher "%s" needs at least two ledger entries (one debit, one credit).',
                $number,
            ));
        }

        $debits = $this->total(Side::Debit);
        $credits = $this->total(Side::Credit);

        if ($debits !== $credits) {
            throw new UnbalancedVoucherException($number, $debits, $credits);
        }
    }

    public function total(Side $side): int
    {
        $sum = 0;
        foreach ($this->entries as $entry) {
            if ($entry->side === $side) {
                $sum += $entry->paise;
            }
        }

        return $sum;
    }

    /** Sum of the signed AMOUNT values; always 0 for a valid voucher. */
    public function signedSum(): int
    {
        return array_sum(array_map(static fn (LedgerEntry $e): int => $e->signedAmount(), $this->entries));
    }

    public function reportName(): string
    {
        return 'Vouchers';
    }

    public function writeXml(XMLWriter $xml): void
    {
        $date = $this->date->format('Ymd');

        $xml->startElement('VOUCHER');
        $xml->writeAttribute('VCHTYPE', Text::clean($this->voucherType));
        $xml->writeAttribute('ACTION', $this->action);
        $xml->writeAttribute('OBJVIEW', self::VIEW);

        $xml->writeElement('DATE', $date);
        $xml->writeElement('EFFECTIVEDATE', $date);
        $xml->writeElement('VOUCHERTYPENAME', Text::clean($this->voucherType));
        $xml->writeElement('VOUCHERNUMBER', Text::clean($this->number));

        if ($this->reference !== null) {
            $xml->writeElement('REFERENCE', Text::clean($this->reference));
        }
        if ($this->partyLedgerName !== null) {
            $xml->writeElement('PARTYLEDGERNAME', Text::clean($this->partyLedgerName));
        }
        if ($this->partyGstin !== null) {
            $xml->writeElement('PARTYGSTIN', $this->partyGstin);
        }
        if ($this->placeOfSupply !== null) {
            $xml->writeElement('PLACEOFSUPPLY', Text::clean($this->placeOfSupply));
        }
        if ($this->narration !== null) {
            $xml->writeElement('NARRATION', Text::clean($this->narration));
        }

        $xml->writeElement('PERSISTEDVIEW', self::VIEW);
        $xml->writeElement('ISINVOICE', 'No');

        foreach ($this->entries as $entry) {
            $this->writeEntry($xml, $entry);
        }

        $xml->endElement();
    }

    private function writeEntry(XMLWriter $xml, LedgerEntry $entry): void
    {
        $xml->startElement('ALLLEDGERENTRIES.LIST');
        $xml->writeElement('LEDGERNAME', Text::clean($entry->ledgerName));
        $xml->writeElement('ISDEEMEDPOSITIVE', Text::yesNo($entry->side->isDeemedPositive()));
        $xml->writeElement('ISPARTYLEDGER', Text::yesNo($entry->isPartyLedger));
        $xml->writeElement('AMOUNT', Amount::format($entry->signedAmount()));

        foreach ($entry->billAllocations as $bill) {
            $xml->startElement('BILLALLOCATIONS.LIST');
            if ($bill->name !== '') {
                $xml->writeElement('NAME', Text::clean($bill->name));
            }
            $xml->writeElement('BILLTYPE', $bill->type->value);
            $xml->writeElement('AMOUNT', Amount::format($entry->side->signedForTally($bill->paise)));
            $xml->endElement();
        }

        $xml->endElement();
    }
}
