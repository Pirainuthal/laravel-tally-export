<?php

declare(strict_types=1);

namespace Pirainuthal\TallyExport\Data;

/**
 * Bill-wise reference types used in BILLALLOCATIONS.LIST.
 */
enum BillType: string
{
    case NewRef = 'New Ref';
    case AgainstRef = 'Agst Ref';
    case Advance = 'Advance';
    case OnAccount = 'On Account';
}
