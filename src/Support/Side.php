<?php

declare(strict_types=1);

namespace Pirainuthal\TallyExport\Support;

/**
 * Debit or credit side of a ledger entry.
 *
 * Tally's XML convention (see README "Sign conventions"):
 *   Debit  -> ISDEEMEDPOSITIVE = Yes, AMOUNT negative
 *   Credit -> ISDEEMEDPOSITIVE = No,  AMOUNT positive
 */
enum Side: string
{
    case Debit = 'Dr';
    case Credit = 'Cr';

    public function isDeemedPositive(): bool
    {
        return $this === self::Debit;
    }

    /** Signed paise as written to the AMOUNT tag. */
    public function signedForTally(int $paise): int
    {
        return $this === self::Debit ? -$paise : $paise;
    }

    public function opposite(): self
    {
        return $this === self::Debit ? self::Credit : self::Debit;
    }
}
