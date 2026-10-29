<?php

declare(strict_types=1);

namespace Pirainuthal\TallyExport\Builders;

use Pirainuthal\TallyExport\Data\BillAllocation;
use Pirainuthal\TallyExport\Data\BillType;
use Pirainuthal\TallyExport\Data\LedgerEntry;
use Pirainuthal\TallyExport\Data\Voucher;
use Pirainuthal\TallyExport\Exceptions\InvalidVoucherException;
use Pirainuthal\TallyExport\Support\Amount;
use Pirainuthal\TallyExport\Support\Side;

/**
 * Receipt voucher: the party is credited, the bank/cash ledger is debited.
 *
 *     TallyVoucher::receipt()
 *         ->number('RCT-0007')->date('2026-04-30')
 *         ->party('Kaveri Traders', 1180)
 *         ->depositTo('State Bank Current A/c')
 *         ->againstInvoice('INV-2026-0042')
 *         ->toXml();
 */
final class ReceiptVoucherBuilder extends VoucherBuilder
{
    private ?string $party = null;

    private int $partyPaise = 0;

    private ?string $depositLedger = null;

    /** @var list<array{ledger: string, paise: int}> */
    private array $deductions = [];

    /** @var list<array{type: BillType, name: string, paise: ?int}> */
    private array $bills = [];

    protected function defaultVoucherType(): string
    {
        return $this->defaults->receiptVoucherType;
    }

    /** The customer ledger that is credited with the full amount settled. */
    public function party(string $ledger, int|float|string $amount): self
    {
        $this->party = $ledger;
        $this->partyPaise = $this->positivePaise($amount, 'Receipt amount');

        return $this;
    }

    /** Bank or cash ledger that receives the money (debited). */
    public function depositTo(string $ledger): self
    {
        $this->depositLedger = $ledger;

        return $this;
    }

    /** A deduction by the customer, e.g. TDS receivable or bank charges (debited). */
    public function deduct(string $ledger, int|float|string $amount): self
    {
        $this->deductions[] = ['ledger' => $ledger, 'paise' => $this->positivePaise($amount, sprintf('Deduction "%s"', $ledger))];

        return $this;
    }

    /**
     * Settles an existing invoice ("Agst Ref"). Without an amount the whole
     * unallocated balance is applied. Any unallocated remainder becomes "On Account".
     */
    public function againstInvoice(string $invoiceNumber, int|float|string|null $amount = null): self
    {
        $this->bills[] = [
            'type' => BillType::AgainstRef,
            'name' => $invoiceNumber,
            'paise' => $amount === null ? null : $this->positivePaise($amount, 'Allocated amount'),
        ];

        return $this;
    }

    /** Records an advance against a future bill reference. */
    public function advance(string $reference, int|float|string $amount): self
    {
        $this->bills[] = ['type' => BillType::Advance, 'name' => $reference, 'paise' => $this->positivePaise($amount, 'Advance')];

        return $this;
    }

    public function build(): Voucher
    {
        $number = $this->requireNumber();
        $date = $this->requireDate();

        if ($this->party === null || trim($this->party) === '') {
            throw new InvalidVoucherException(sprintf('Receipt "%s" needs a party() with an amount.', $number));
        }

        if ($this->depositLedger === null || trim($this->depositLedger) === '') {
            throw new InvalidVoucherException(sprintf('Receipt "%s" needs depositTo() a bank or cash ledger.', $number));
        }

        $deducted = array_sum(array_column($this->deductions, 'paise'));
        $banked = $this->partyPaise - $deducted;

        if ($banked <= 0) {
            throw new InvalidVoucherException(sprintf(
                'Receipt "%s": deductions (%s) must be less than the receipt amount (%s).',
                $number,
                Amount::format($deducted),
                Amount::format($this->partyPaise),
            ));
        }

        /** @var list<array{0: string, 1: Side, 2: int}> $rows */
        $rows = [[$this->depositLedger, Side::Debit, $banked]];
        foreach ($this->deductions as $deduction) {
            $rows[] = [$deduction['ledger'], Side::Debit, $deduction['paise']];
        }

        $entries = $this->mergeEntries($rows);
        array_unshift($entries, new LedgerEntry($this->party, Side::Credit, $this->partyPaise, true, $this->allocations($number)));

        return new Voucher(
            voucherType: $this->voucherType,
            number: $number,
            date: $date,
            entries: $entries,
            partyLedgerName: $this->party,
            narration: $this->narration,
            reference: $this->reference,
            action: $this->action,
        );
    }

    /**
     * @return list<BillAllocation>
     */
    private function allocations(string $number): array
    {
        if ($this->bills === []) {
            return [];
        }

        $remaining = $this->partyPaise;
        $allocations = [];

        foreach ($this->bills as $bill) {
            $paise = $bill['paise'] ?? $remaining;

            if ($paise > $remaining || $paise <= 0) {
                throw new InvalidVoucherException(sprintf(
                    'Receipt "%s": bill allocations exceed the receipt amount of %s.',
                    $number,
                    Amount::format($this->partyPaise),
                ));
            }

            $allocations[] = new BillAllocation($bill['type'], $paise, $bill['name']);
            $remaining -= $paise;
        }

        if ($remaining > 0) {
            $allocations[] = new BillAllocation(BillType::OnAccount, $remaining);
        }

        return $allocations;
    }
}
