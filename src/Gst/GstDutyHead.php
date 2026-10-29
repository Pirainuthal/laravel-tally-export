<?php

declare(strict_types=1);

namespace Pirainuthal\TallyExport\Gst;

/**
 * GSTDUTYHEAD values for "Duties & Taxes" ledgers whose tax type is GST.
 * SGST and UTGST ledgers both use State Tax.
 */
enum GstDutyHead: string
{
    case Central = 'Central Tax';
    case State = 'State Tax';
    case Integrated = 'Integrated Tax';
    case Cess = 'Cess';
}
