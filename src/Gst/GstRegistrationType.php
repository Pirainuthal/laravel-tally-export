<?php

declare(strict_types=1);

namespace Pirainuthal\TallyExport\Gst;

/**
 * GSTREGISTRATIONTYPE values as shown in Tally's party ledger GST details.
 */
enum GstRegistrationType: string
{
    case Regular = 'Regular';
    case Composition = 'Composition';
    case Consumer = 'Consumer';
    case Unregistered = 'Unregistered';
    case Unknown = 'Unknown';
}
