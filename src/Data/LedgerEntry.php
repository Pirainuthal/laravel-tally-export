<?php

declare(strict_types=1);

namespace Pirainuthal\TallyExport\Data;

use Pirainuthal\TallyExport\Exceptions\InvalidVoucherException;
use Pirainuthal\TallyExport\Support\Amount;
use Pirainuthal\TallyExport\Support\Side;

/**
 * One line of ALLLEDGERENTRIES.LIST. Amounts are unsigned paise; the side decides the sign.
 */
final class LedgerEntry
{
    /**
     * @param  list<BillAllocation>  $billAllocations
     */
    public function __construct(
        public readonly string $ledgerName,
        public readonly Side $side,
        public readonly int $paise,
        public readonly bool $isPartyLedger = false,
        public readonly array $billAllocations = [],
    ) {
        if (trim($ledgerName) === '') {
            throw new InvalidVoucherException('Ledger name must not be empty.');
        }

        if ($paise <= 0) {
            throw new InvalidVoucherException(sprintf(
                'Amount for ledger "%s" must be greater than zero, got %s.',
                $ledgerName,
                Amount::format($paise),
            ));
        }

        if ($billAllocations !== []) {
            $allocated = array_sum(array_map(static fn (BillAllocation $b): int => $b->paise, $billAllocations));

            if ($allocated !== $paise) {
                throw new InvalidVoucherException(sprintf(
                    'Bill allocations for ledger "%s" add up to %s but the entry is %s.',
                    $ledgerName,
                    Amount::format($allocated),
                    Amount::format($paise),
                ));
            }
        }
    }

    public function signedAmount(): int
    {
        return $this->side->signedForTally($this->paise);
    }
}
