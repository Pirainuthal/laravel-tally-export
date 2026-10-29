<?php

declare(strict_types=1);

namespace Pirainuthal\TallyExport\Builders;

use Pirainuthal\TallyExport\Data\Ledger;
use Pirainuthal\TallyExport\Gst\GstDutyHead;
use Pirainuthal\TallyExport\Gst\Gstin;
use Pirainuthal\TallyExport\Gst\GstRegistrationType;
use Pirainuthal\TallyExport\Support\Amount;
use Pirainuthal\TallyExport\Support\Side;
use Pirainuthal\TallyExport\Xml\EnvelopeWriter;

/**
 * Fluent builder for a ledger master.
 *
 *     TallyLedger::customer('Kaveri Traders')
 *         ->gstin('33ABCDE1234F1Z7')
 *         ->state('Tamil Nadu')
 *         ->toXml();
 */
final class LedgerBuilder
{
    private ?bool $billWise = null;

    /** @var list<string> */
    private array $address = [];

    private ?string $state = null;

    private ?string $country = null;

    private ?string $pincode = null;

    private ?string $email = null;

    private ?Gstin $gstin = null;

    private ?GstRegistrationType $registrationType = null;

    private ?GstDutyHead $dutyHead = null;

    private int $openingPaise = 0;

    private Side $openingSide = Side::Debit;

    private string $action = 'Create';

    public function __construct(
        private string $name,
        private string $parent = '',
    ) {}

    public function name(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    /** Parent group, e.g. "Sundry Debtors", "Sales Accounts", "Duties & Taxes". */
    public function under(string $group): self
    {
        $this->parent = $group;

        return $this;
    }

    public function billWise(bool $enabled = true): self
    {
        $this->billWise = $enabled;

        return $this;
    }

    public function address(string ...$lines): self
    {
        $this->address = array_values(array_filter($lines, static fn (string $l): bool => trim($l) !== ''));

        return $this;
    }

    /** State name exactly as it appears in your Tally company, e.g. "Tamil Nadu". */
    public function state(string $state): self
    {
        $this->state = $state;

        return $this;
    }

    /** Fills the state from the GSTIN's state code (official spelling). Call after gstin(). */
    public function stateFromGstin(): self
    {
        if ($this->gstin !== null) {
            $this->state = $this->gstin->stateName();
        }

        return $this;
    }

    public function country(string $country): self
    {
        $this->country = $country;

        return $this;
    }

    public function pincode(string $pincode): self
    {
        $this->pincode = $pincode;

        return $this;
    }

    public function email(string $email): self
    {
        $this->email = $email;

        return $this;
    }

    /**
     * Sets and validates the GSTIN (format, state code and check character).
     * Registration type defaults to Regular and country to India.
     */
    public function gstin(string $gstin): self
    {
        $this->gstin = Gstin::parse($gstin);
        $this->registrationType ??= GstRegistrationType::Regular;
        $this->country ??= 'India';

        return $this;
    }

    public function registrationType(GstRegistrationType $type): self
    {
        $this->registrationType = $type;

        return $this;
    }

    /** Marks the ledger as a GST duty ledger (TAXTYPE = GST). */
    public function dutyHead(GstDutyHead $head): self
    {
        $this->dutyHead = $head;

        return $this;
    }

    public function openingBalance(int|float|string $amount, Side $side = Side::Debit): self
    {
        $paise = Amount::toPaise($amount);
        $this->openingPaise = abs($paise);
        $this->openingSide = $paise < 0 ? $side->opposite() : $side;

        return $this;
    }

    /** "Create" (default) or "Alter" for an existing master. */
    public function action(string $action): self
    {
        $this->action = $action;

        return $this;
    }

    public function build(): Ledger
    {
        return new Ledger(
            name: $this->name,
            parent: $this->parent,
            billWise: $this->billWise,
            address: $this->address,
            state: $this->state,
            country: $this->country,
            pincode: $this->pincode,
            email: $this->email,
            gstin: $this->gstin,
            registrationType: $this->registrationType,
            dutyHead: $this->dutyHead,
            openingPaise: $this->openingPaise,
            openingSide: $this->openingSide,
            action: $this->action,
        );
    }

    public function toXml(?string $company = null): string
    {
        return (new EnvelopeWriter($company))->write([$this->build()]);
    }
}
