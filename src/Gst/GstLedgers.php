<?php

declare(strict_types=1);

namespace Pirainuthal\TallyExport\Gst;

/**
 * Names of the duty ledgers that automatic GST lines are posted to.
 *
 * Each name may contain "{rate}", replaced with the rate of that particular
 * tax (CGST/SGST get half the GST rate), e.g. "Output CGST {rate}%" -> "Output CGST 9%".
 */
final class GstLedgers
{
    public function __construct(
        public readonly string $cgst = 'CGST',
        public readonly string $sgst = 'SGST',
        public readonly string $igst = 'IGST',
    ) {}

    /**
     * @param  array<string, mixed>  $config
     */
    public static function fromArray(array $config): self
    {
        return new self(
            cgst: self::string($config, 'cgst', 'CGST'),
            sgst: self::string($config, 'sgst', 'SGST'),
            igst: self::string($config, 'igst', 'IGST'),
        );
    }

    /**
     * @param  string  $rate  the rate label of this particular tax, e.g. "9"
     */
    public static function resolve(string $pattern, string $rate): string
    {
        return str_replace('{rate}', $rate, $pattern);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private static function string(array $config, string $key, string $default): string
    {
        $value = $config[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : $default;
    }
}
