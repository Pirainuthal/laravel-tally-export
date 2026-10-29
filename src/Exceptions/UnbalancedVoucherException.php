<?php

declare(strict_types=1);

namespace Pirainuthal\TallyExport\Exceptions;

use Pirainuthal\TallyExport\Support\Amount;

/**
 * Total debits and total credits of a voucher differ.
 */
class UnbalancedVoucherException extends InvalidVoucherException
{
    public function __construct(
        public readonly string $voucherNumber,
        public readonly int $debitPaise,
        public readonly int $creditPaise,
        string $hint = '',
    ) {
        parent::__construct(sprintf(
            'Voucher "%s" is not balanced: debits %s, credits %s (difference %s).%s',
            $voucherNumber,
            Amount::format($debitPaise),
            Amount::format($creditPaise),
            Amount::format($debitPaise - $creditPaise),
            $hint === '' ? '' : ' '.$hint,
        ));
    }
}
