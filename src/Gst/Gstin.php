<?php

declare(strict_types=1);

namespace Pirainuthal\TallyExport\Gst;

use Pirainuthal\TallyExport\Exceptions\InvalidGstinException;
use Stringable;

/**
 * A validated 15-character GST Identification Number.
 *
 * Layout: 2-digit state code + 10-character PAN (or other id) + entity
 * number + default "Z" + check character. The check character is the
 * base-36 Luhn ("mod 36") checksum used by GSTN.
 */
final class Gstin implements Stringable
{
    private const CHARSET = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';

    private function __construct(public readonly string $value) {}

    public static function parse(string $gstin): self
    {
        $value = strtoupper(trim($gstin));
        $reason = self::invalidReason($value);

        if ($reason !== null) {
            throw new InvalidGstinException(sprintf('Invalid GSTIN "%s": %s.', $gstin, $reason));
        }

        return new self($value);
    }

    public static function isValid(string $gstin): bool
    {
        return self::invalidReason(strtoupper(trim($gstin))) === null;
    }

    /**
     * Computes the check character for the first 14 characters of a GSTIN.
     */
    public static function checkCharacter(string $first14): string
    {
        $first14 = strtoupper($first14);

        if (preg_match('/^[0-9A-Z]{14}$/', $first14) !== 1) {
            throw new InvalidGstinException('Check character needs exactly 14 alphanumeric characters.');
        }

        $sum = 0;
        for ($i = 0; $i < 14; $i++) {
            $product = strpos(self::CHARSET, $first14[$i]) * ($i % 2 === 0 ? 1 : 2);
            $sum += intdiv($product, 36) + $product % 36;
        }

        return self::CHARSET[(36 - $sum % 36) % 36];
    }

    public function stateCode(): string
    {
        return substr($this->value, 0, 2);
    }

    public function stateName(): string
    {
        return (string) StateCodes::name($this->stateCode());
    }

    /** The embedded PAN, when characters 3-12 follow the PAN pattern. */
    public function pan(): ?string
    {
        $pan = substr($this->value, 2, 10);

        return preg_match('/^[A-Z]{5}[0-9]{4}[A-Z]$/', $pan) === 1 ? $pan : null;
    }

    public function __toString(): string
    {
        return $this->value;
    }

    private static function invalidReason(string $value): ?string
    {
        if (preg_match('/^[0-9]{2}[0-9A-Z]{13}$/', $value) !== 1) {
            return 'expected 2 digits followed by 13 letters or digits';
        }

        if (StateCodes::name(substr($value, 0, 2)) === null) {
            return sprintf('unknown state code "%s"', substr($value, 0, 2));
        }

        $expected = self::checkCharacter(substr($value, 0, 14));
        if ($value[14] !== $expected) {
            return sprintf('check character should be "%s"', $expected);
        }

        return null;
    }
}
