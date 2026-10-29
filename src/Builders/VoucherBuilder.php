<?php

declare(strict_types=1);

namespace Pirainuthal\TallyExport\Builders;

use DateTimeImmutable;
use DateTimeInterface;
use Pirainuthal\TallyExport\Data\LedgerEntry;
use Pirainuthal\TallyExport\Data\Voucher;
use Pirainuthal\TallyExport\Exceptions\InvalidVoucherException;
use Pirainuthal\TallyExport\Support\Amount;
use Pirainuthal\TallyExport\Support\Side;
use Pirainuthal\TallyExport\Support\TallyDate;
use Pirainuthal\TallyExport\Xml\EnvelopeWriter;

/**
 * Shared fluent API for every voucher builder.
 */
abstract class VoucherBuilder
{
    protected ?string $number = null;

    protected ?DateTimeImmutable $date = null;

    protected ?string $narration = null;

    protected ?string $reference = null;

    protected string $voucherType;

    protected string $action = 'Create';

    public function __construct(protected readonly BuilderDefaults $defaults = new BuilderDefaults)
    {
        $this->voucherType = $this->defaultVoucherType();
    }

    abstract protected function defaultVoucherType(): string;

    abstract public function build(): Voucher;

    public function number(string|int $number): static
    {
        $this->number = (string) $number;

        return $this;
    }

    /** DateTimeInterface or "Y-m-d", "Ymd", "d-m-Y", "d/m/Y". Written as YYYYMMDD. */
    public function date(DateTimeInterface|string $date): static
    {
        $this->date = TallyDate::parse($date);

        return $this;
    }

    public function narration(?string $narration): static
    {
        $this->narration = $narration;

        return $this;
    }

    /** Supplier invoice / PO / external reference (REFERENCE tag). */
    public function reference(?string $reference): static
    {
        $this->reference = $reference;

        return $this;
    }

    /** Use a custom voucher type name, e.g. "GST Sales". Its parent type must match. */
    public function voucherType(string $name): static
    {
        $this->voucherType = $name;

        return $this;
    }

    /** "Create" (default) or "Alter". */
    public function action(string $action): static
    {
        $this->action = $action;

        return $this;
    }

    /** One envelope containing only this voucher. */
    public function toXml(?string $company = null): string
    {
        return (new EnvelopeWriter($company))->write([$this->build()]);
    }

    protected function requireNumber(): string
    {
        if ($this->number === null || trim($this->number) === '') {
            throw new InvalidVoucherException(sprintf('%s voucher needs a number(); none was set.', $this->voucherType));
        }

        return $this->number;
    }

    protected function requireDate(): DateTimeImmutable
    {
        if ($this->date === null) {
            throw new InvalidVoucherException(sprintf('%s voucher "%s" needs a date().', $this->voucherType, $this->number ?? '?'));
        }

        return $this->date;
    }

    protected function positivePaise(int|float|string $amount, string $what): int
    {
        $paise = Amount::toPaise($amount);

        if ($paise <= 0) {
            throw new InvalidVoucherException(sprintf(
                '%s must be greater than zero, got %s.',
                $what,
                Amount::format($paise),
            ));
        }

        return $paise;
    }

    /**
     * Merges amounts posted to the same ledger on the same side, keeping first-seen order.
     *
     * @param  list<array{0: string, 1: Side, 2: int}>  $rows
     * @return list<LedgerEntry>
     */
    protected function mergeEntries(array $rows): array
    {
        /** @var array<string, array{0: string, 1: Side, 2: int}> $merged */
        $merged = [];

        foreach ($rows as [$ledger, $side, $paise]) {
            $key = $side->value.'|'.$ledger;
            if (isset($merged[$key])) {
                $merged[$key][2] += $paise;
            } else {
                $merged[$key] = [$ledger, $side, $paise];
            }
        }

        return array_values(array_map(
            static fn (array $row): LedgerEntry => new LedgerEntry($row[0], $row[1], $row[2]),
            array_filter($merged, static fn (array $row): bool => $row[2] !== 0),
        ));
    }
}
