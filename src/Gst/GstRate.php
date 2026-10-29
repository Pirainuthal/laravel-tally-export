<?php

declare(strict_types=1);

namespace Pirainuthal\TallyExport\Gst;

use Pirainuthal\TallyExport\Exceptions\InvalidVoucherException;

/**
 * GST rates are handled in basis points (1% = 100 bp) to keep tax maths exact.
 *
 * Any rate between 0 and 100 with at most two decimals is accepted; the
 * package deliberately does not hard-code the notified slabs, which change.
 */
final class GstRate
{
    private function __construct() {}

    public static function toBasisPoints(int|float|string $percent): int
    {
        $value = is_string($percent) ? trim($percent) : (string) $percent;

        if (preg_match('/^\d+(?:\.\d{1,2})?$/', $value) !== 1) {
            throw new InvalidVoucherException(sprintf('"%s" is not a valid GST rate (use e.g. 5, 18 or 0.25).', $value));
        }

        [$whole, $fraction] = array_pad(explode('.', $value), 2, '');
        $bp = (int) $whole * 100 + (int) str_pad($fraction, 2, '0');

        if ($bp > 10000) {
            throw new InvalidVoucherException(sprintf('GST rate %s%% is above 100%%.', $value));
        }

        return $bp;
    }

    /** 1800 -> "18", 250 -> "2.5", 25 -> "0.25", 1250 -> "12.5" */
    public static function format(int $basisPoints): string
    {
        $text = sprintf('%d.%02d', intdiv($basisPoints, 100), $basisPoints % 100);

        return rtrim(rtrim($text, '0'), '.');
    }

    /** Half of a rate, as used for CGST and SGST: 1800 -> "9", 25 -> "0.125". */
    public static function formatHalf(int $basisPoints): string
    {
        $milliPercent = $basisPoints * 5;
        $text = sprintf('%d.%03d', intdiv($milliPercent, 1000), $milliPercent % 1000);

        return rtrim(rtrim($text, '0'), '.');
    }
}
