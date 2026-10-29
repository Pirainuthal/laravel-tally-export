<?php

declare(strict_types=1);

namespace Pirainuthal\TallyExport\Data;

use Pirainuthal\TallyExport\Exceptions\InvalidVoucherException;

/**
 * One bill-wise reference inside a party ledger entry.
 * The amount is unsigned; it takes the side of the entry it belongs to.
 */
final class BillAllocation
{
    public function __construct(
        public readonly BillType $type,
        public readonly int $paise,
        public readonly string $name = '',
    ) {
        if ($paise <= 0) {
            throw new InvalidVoucherException('Bill allocation amount must be greater than zero.');
        }

        if ($type !== BillType::OnAccount && trim($name) === '') {
            throw new InvalidVoucherException(sprintf('Bill allocation of type "%s" needs a reference name.', $type->value));
        }
    }
}
