<?php

declare(strict_types=1);

namespace Pirainuthal\TallyExport\Support;

use DateTimeImmutable;
use DateTimeInterface;
use Pirainuthal\TallyExport\Exceptions\InvalidVoucherException;

/**
 * Normalises dates to Tally's YYYYMMDD import format.
 */
final class TallyDate
{
    /** Accepted string formats, tried in order. */
    private const FORMATS = ['Y-m-d', 'Ymd', 'd-m-Y', 'd/m/Y'];

    private function __construct() {}

    public static function format(DateTimeInterface|string $date): string
    {
        return self::parse($date)->format('Ymd');
    }

    public static function parse(DateTimeInterface|string $date): DateTimeImmutable
    {
        if ($date instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($date);
        }

        $value = trim($date);

        foreach (self::FORMATS as $format) {
            $parsed = DateTimeImmutable::createFromFormat('!'.$format, $value);

            // Round-tripping rejects overflow such as 2026-02-30 -> 2026-03-02.
            if ($parsed !== false && $parsed->format($format) === $value) {
                return $parsed;
            }
        }

        throw new InvalidVoucherException(sprintf(
            '"%s" is not a valid date. Use a DateTimeInterface or one of: %s.',
            $date,
            implode(', ', self::FORMATS),
        ));
    }
}
