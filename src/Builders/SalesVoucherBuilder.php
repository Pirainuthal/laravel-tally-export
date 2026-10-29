<?php

declare(strict_types=1);

namespace Pirainuthal\TallyExport\Builders;

use Pirainuthal\TallyExport\Data\BillAllocation;
use Pirainuthal\TallyExport\Data\BillType;
use Pirainuthal\TallyExport\Data\LedgerEntry;
use Pirainuthal\TallyExport\Data\Voucher;
use Pirainuthal\TallyExport\Exceptions\InvalidVoucherException;
use Pirainuthal\TallyExport\Exceptions\UnbalancedVoucherException;
use Pirainuthal\TallyExport\Gst\Gstin;
use Pirainuthal\TallyExport\Gst\GstLedgers;
use Pirainuthal\TallyExport\Gst\GstRate;
use Pirainuthal\TallyExport\Support\Amount;
use Pirainuthal\TallyExport\Support\Side;

/**
 * Sales voucher (accounting mode): party is debited, sales and duty ledgers are credited.
 *
 *     TallyVoucher::sales()
 *         ->number('INV-2026-0042')->date('2026-04-15')
 *         ->party('Kaveri Traders')
 *         ->addLine('Sales @ 18%', 1000, gstRate: 18)
 *         ->applyGst(interState: false)
 *         ->roundOff()
 *         ->toXml();
 */
final class SalesVoucherBuilder extends VoucherBuilder
{
    private ?string $party = null;

    private ?int $partyTotal = null;

    private ?string $partyGstin = null;

    private ?string $placeOfSupply = null;

    /** @var list<array{ledger: string, paise: int, rate: ?int}> */
    private array $lines = [];

    /** @var list<array{ledger: string, paise: int}> */
    private array $taxes = [];

    /** @var list<array{ledger: string, paise: int, rate: ?int}> */
    private array $discounts = [];

    private ?bool $interState = null;

    private ?GstLedgers $gstLedgers = null;

    private ?string $roundOffLedger = null;

    private ?string $billReference = null;

    protected function defaultVoucherType(): string
    {
        return $this->defaults->salesVoucherType;
    }

    /**
     * The customer ledger that is debited.
     *
     * @param  int|float|string|null  $total  optional invoice total; when given, the
     *                                        voucher must add up to exactly this amount
     */
    public function party(string $ledger, int|float|string|null $total = null): self
    {
        $this->party = $ledger;
        $this->partyTotal = $total === null ? null : $this->positivePaise($total, 'Party total');

        return $this;
    }

    /** Party GSTIN printed on the voucher (validated). */
    public function partyGstin(string $gstin): self
    {
        $this->partyGstin = Gstin::parse($gstin)->value;

        return $this;
    }

    /** Place of supply state name, e.g. "Tamil Nadu". */
    public function placeOfSupply(string $state): self
    {
        $this->placeOfSupply = $state;

        return $this;
    }

    /**
     * Credits a sales (income) ledger with the taxable value.
     *
     * @param  int|float|string|null  $gstRate  GST % for applyGst(), e.g. 18
     */
    public function addLine(string $ledger, int|float|string $amount, int|float|string|null $gstRate = null): self
    {
        $paise = Amount::toPaise($amount);

        if ($paise <= 0) {
            throw new InvalidVoucherException(sprintf(
                'Sales line "%s" must be greater than zero, got %s. Use addDiscount() for reductions.',
                $ledger,
                Amount::format($paise),
            ));
        }

        $this->lines[] = [
            'ledger' => $ledger,
            'paise' => $paise,
            'rate' => $gstRate === null ? null : GstRate::toBasisPoints($gstRate),
        ];

        return $this;
    }

    /** Credits a duty ledger with an explicit tax amount (CGST, SGST, IGST, cess, TCS...). */
    public function addTax(string $ledger, int|float|string $amount): self
    {
        $this->taxes[] = ['ledger' => $ledger, 'paise' => $this->positivePaise($amount, sprintf('Tax "%s"', $ledger))];

        return $this;
    }

    /**
     * Debits a discount ledger (reduces the party total).
     *
     * A discount shown on the invoice reduces the taxable value (CGST Act 2017,
     * Section 15(3)(a)). When applyGst() is used, pass the gstRate of the lines
     * the discount belongs to, so tax is computed on the discounted value.
     * Use gstRate: 0 for a discount on nil-rated or exempt lines.
     *
     * @param  int|float|string|null  $gstRate  GST % of the discounted lines, e.g. 18
     */
    public function addDiscount(string $ledger, int|float|string $amount, int|float|string|null $gstRate = null): self
    {
        $this->discounts[] = [
            'ledger' => $ledger,
            'paise' => $this->positivePaise($amount, sprintf('Discount "%s"', $ledger)),
            'rate' => $gstRate === null ? null : GstRate::toBasisPoints($gstRate),
        ];

        return $this;
    }

    /**
     * Computes GST from every line that has a gstRate: CGST + SGST for an
     * intra-state supply, IGST for an inter-state supply. Tax is calculated
     * per rate on the summed taxable value (less any discounts at that rate)
     * and rounded to the paisa.
     */
    public function applyGst(bool $interState, ?GstLedgers $ledgers = null): self
    {
        $this->interState = $interState;
        $this->gstLedgers = $ledgers;

        return $this;
    }

    /** Rounds the party total to the nearest rupee and posts the difference. */
    public function roundOff(?string $ledger = null): self
    {
        $this->roundOffLedger = $ledger ?? $this->defaults->roundOffLedger;

        return $this;
    }

    /**
     * Adds a "New Ref" bill allocation on the party entry so the invoice
     * appears in Tally's outstanding receivables. Defaults to the voucher number.
     */
    public function billWise(?string $reference = null): self
    {
        $this->billReference = $reference ?? '';

        return $this;
    }

    public function build(): Voucher
    {
        $number = $this->requireNumber();
        $date = $this->requireDate();

        if ($this->party === null || trim($this->party) === '') {
            throw new InvalidVoucherException(sprintf('Sales voucher "%s" needs a party().', $number));
        }

        if ($this->lines === []) {
            throw new InvalidVoucherException(sprintf('Sales voucher "%s" needs at least one addLine().', $number));
        }

        /** @var list<array{0: string, 1: Side, 2: int}> $rows */
        $rows = [];
        foreach ($this->lines as $line) {
            $rows[] = [$line['ledger'], Side::Credit, $line['paise']];
        }
        foreach ($this->taxes as $tax) {
            $rows[] = [$tax['ledger'], Side::Credit, $tax['paise']];
        }
        foreach ($this->gstRows() as $row) {
            $rows[] = $row;
        }
        foreach ($this->discounts as $discount) {
            $rows[] = [$discount['ledger'], Side::Debit, $discount['paise']];
        }

        $subtotal = 0;
        foreach ($rows as [, $side, $paise]) {
            $subtotal += $side === Side::Credit ? $paise : -$paise;
        }

        if ($this->roundOffLedger !== null) {
            $difference = Amount::roundToRupee($subtotal) - $subtotal;
            if ($difference !== 0) {
                $rows[] = [$this->roundOffLedger, $difference > 0 ? Side::Credit : Side::Debit, abs($difference)];
                $subtotal += $difference;
            }
        }

        $entries = $this->mergeEntries($rows);
        $credits = array_sum(array_map(static fn (LedgerEntry $e): int => $e->side === Side::Credit ? $e->paise : 0, $entries));
        $debits = array_sum(array_map(static fn (LedgerEntry $e): int => $e->side === Side::Debit ? $e->paise : 0, $entries));

        if ($this->partyTotal !== null && $this->partyTotal !== $subtotal) {
            throw new UnbalancedVoucherException(
                $number,
                $this->partyTotal + $debits,
                $credits,
                sprintf(
                    'Party total %s does not match lines + taxes - discounts%s = %s.',
                    Amount::format($this->partyTotal),
                    $this->roundOffLedger !== null ? ' + round-off' : '',
                    Amount::format($subtotal),
                ),
            );
        }

        if ($subtotal <= 0) {
            throw new InvalidVoucherException(sprintf(
                'Sales voucher "%s" has a non-positive total (%s); discounts exceed the lines.',
                $number,
                Amount::format($subtotal),
            ));
        }

        $bills = $this->billReference === null ? [] : [
            new BillAllocation(BillType::NewRef, $subtotal, $this->billReference !== '' ? $this->billReference : $number),
        ];

        array_unshift($entries, new LedgerEntry($this->party, Side::Debit, $subtotal, true, $bills));

        return new Voucher(
            voucherType: $this->voucherType,
            number: $number,
            date: $date,
            entries: $entries,
            partyLedgerName: $this->party,
            narration: $this->narration,
            reference: $this->reference,
            partyGstin: $this->partyGstin,
            placeOfSupply: $this->placeOfSupply,
            action: $this->action,
        );
    }

    /**
     * @return list<array{0: string, 1: Side, 2: int}>
     */
    private function gstRows(): array
    {
        if ($this->interState === null) {
            return [];
        }

        $number = $this->number ?? '?';

        /** @var array<int, int> $taxableByRate basis points => taxable paise */
        $taxableByRate = [];
        foreach ($this->lines as $line) {
            if ($line['rate'] !== null) {
                $taxableByRate[$line['rate']] = ($taxableByRate[$line['rate']] ?? 0) + $line['paise'];
            }
        }

        if (array_filter(array_keys($taxableByRate), static fn (int $rate): bool => $rate > 0) === []) {
            throw new InvalidVoucherException(sprintf(
                'applyGst() was called on "%s" but no line has a gstRate above zero.',
                $number,
            ));
        }

        // Invoice discounts reduce the taxable value of the lines they belong to (CGST Act s.15(3)(a)).
        foreach ($this->discounts as $discount) {
            $rate = $discount['rate'];

            if ($rate === null) {
                throw new InvalidVoucherException(sprintf(
                    'Discount "%s" on "%s" needs a gstRate when applyGst() is used: a discount shown on the invoice '
                    .'reduces the taxable value (CGST Act s.15(3)). Pass the rate of the discounted lines, or 0 for nil-rated lines.',
                    $discount['ledger'],
                    $number,
                ));
            }

            if (! isset($taxableByRate[$rate])) {
                throw new InvalidVoucherException(sprintf(
                    'Discount "%s" on "%s" is at %s%% GST but no line has that gstRate.',
                    $discount['ledger'],
                    $number,
                    GstRate::format($rate),
                ));
            }

            $taxableByRate[$rate] -= $discount['paise'];

            if ($taxableByRate[$rate] < 0) {
                throw new InvalidVoucherException(sprintf(
                    'Discounts at %s%% GST on "%s" exceed the taxable value of the lines at that rate.',
                    GstRate::format($rate),
                    $number,
                ));
            }
        }

        $ledgers = $this->gstLedgers ?? $this->defaults->gstLedgers;
        $rows = [];

        foreach ($taxableByRate as $rate => $taxable) {
            if ($rate === 0 || $taxable === 0) {
                continue;
            }

            if ($this->interState) {
                $rows[] = [GstLedgers::resolve($ledgers->igst, GstRate::format($rate)), Side::Credit, Amount::percentOf($taxable, $rate)];

                continue;
            }

            $half = Amount::percentOf($taxable, $rate, 2);
            $label = GstRate::formatHalf($rate);
            $rows[] = [GstLedgers::resolve($ledgers->cgst, $label), Side::Credit, $half];
            $rows[] = [GstLedgers::resolve($ledgers->sgst, $label), Side::Credit, $half];
        }

        return $rows;
    }
}
