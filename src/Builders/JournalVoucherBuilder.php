<?php

declare(strict_types=1);

namespace Pirainuthal\TallyExport\Builders;

use Pirainuthal\TallyExport\Data\Voucher;
use Pirainuthal\TallyExport\Support\Side;

/**
 * Free-form voucher with explicit debit and credit lines. Works for Journal,
 * Contra, Payment or any custom voucher type via voucherType().
 * build() throws UnbalancedVoucherException when debits and credits differ.
 */
final class JournalVoucherBuilder extends VoucherBuilder
{
    /** @var list<array{0: string, 1: Side, 2: int}> */
    private array $rows = [];

    private ?string $party = null;

    protected function defaultVoucherType(): string
    {
        return $this->defaults->journalVoucherType;
    }

    public function debit(string $ledger, int|float|string $amount): self
    {
        $this->rows[] = [$ledger, Side::Debit, $this->positivePaise($amount, sprintf('Debit to "%s"', $ledger))];

        return $this;
    }

    public function credit(string $ledger, int|float|string $amount): self
    {
        $this->rows[] = [$ledger, Side::Credit, $this->positivePaise($amount, sprintf('Credit to "%s"', $ledger))];

        return $this;
    }

    /** Optional PARTYLEDGERNAME for the voucher header. */
    public function party(string $ledger): self
    {
        $this->party = $ledger;

        return $this;
    }

    public function build(): Voucher
    {
        return new Voucher(
            voucherType: $this->voucherType,
            number: $this->requireNumber(),
            date: $this->requireDate(),
            entries: $this->mergeEntries($this->rows),
            partyLedgerName: $this->party,
            narration: $this->narration,
            reference: $this->reference,
            action: $this->action,
        );
    }
}
