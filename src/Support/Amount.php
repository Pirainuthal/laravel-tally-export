<?php

declare(strict_types=1);

namespace Pirainuthal\TallyExport\Support;

use Pirainuthal\TallyExport\Exceptions\InvalidVoucherException;

/**
 * Converts rupee amounts to integer paise (and back) so that every
 * calculation in the package is exact. Floats never take part in arithmetic.
 *
 * Rounding is "half away from zero" to two decimals, e.g. 10.005 -> 10.01.
 */
final class Amount
{
    private function __construct() {}

    /**
     * @param  int|float|string  $rupees  1180, 1180.5, "1,180.50" or "1180.505"
     */
    public static function toPaise(int|float|string $rupees): int
    {
        if (is_int($rupees)) {
            return $rupees * 100;
        }

        if (is_float($rupees)) {
            if (! is_finite($rupees)) {
                throw new InvalidVoucherException('Amount must be a finite number.');
            }

            $rupees = self::floatToDecimal($rupees);
        }

        $normalised = str_replace([',', ' ', '_'], '', trim($rupees));

        if (preg_match('/^([+-])?(\d+)(?:\.(\d*))?$/', $normalised, $m) !== 1) {
            throw new InvalidVoucherException(sprintf('"%s" is not a valid amount.', $rupees));
        }

        $negative = $m[1] === '-';
        $fraction = $m[3] ?? '';
        $paise = ((int) $m[2]) * 100 + (int) str_pad(substr($fraction, 0, 2), 2, '0');

        if (strlen($fraction) > 2 && (int) $fraction[2] >= 5) {
            $paise++;
        }

        return $negative ? -$paise : $paise;
    }

    /**
     * Writes a float as a plain decimal string with 15 significant digits.
     *
     * Any decimal with up to 15 significant digits survives the trip through
     * a double, so this recovers what the caller wrote (1.005 -> "1.00500...",
     * not "1.00499999..."). Unlike a (string) cast it does not depend on the
     * "precision" ini setting.
     */
    private static function floatToDecimal(float $value): string
    {
        [$mantissa, $exponent] = explode('e', sprintf('%.14e', $value));
        $sign = str_starts_with($mantissa, '-') ? '-' : '';
        $digits = str_replace(['-', '.'], '', $mantissa);
        $integerDigits = (int) $exponent + 1;

        if ($integerDigits <= 0) {
            return $sign.'0.'.str_repeat('0', -$integerDigits).$digits;
        }

        if ($integerDigits >= strlen($digits)) {
            return $sign.$digits.str_repeat('0', $integerDigits - strlen($digits));
        }

        return $sign.substr($digits, 0, $integerDigits).'.'.substr($digits, $integerDigits);
    }

    /**
     * Formats paise as the plain decimal string Tally expects: "-1180.00".
     */
    public static function format(int $paise): string
    {
        $sign = $paise < 0 ? '-' : '';
        $abs = abs($paise);

        return sprintf('%s%d.%02d', $sign, intdiv($abs, 100), $abs % 100);
    }

    /**
     * Multiplies paise by a percentage expressed in basis points
     * (18% = 1800 bp) and rounds half away from zero to whole paise.
     */
    public static function percentOf(int $paise, int $basisPoints, int $divisor = 1): int
    {
        $denominator = 10000 * $divisor;
        $numerator = abs($paise) * $basisPoints;
        $result = intdiv(2 * $numerator + $denominator, 2 * $denominator);

        return $paise < 0 ? -$result : $result;
    }

    /**
     * Rounds paise to the nearest whole rupee, half away from zero
     * (x.50 rounds up), as is customary for invoice totals.
     */
    public static function roundToRupee(int $paise): int
    {
        $abs = abs($paise);
        $rounded = intdiv($abs + 50, 100) * 100;

        return $paise < 0 ? -$rounded : $rounded;
    }
}
