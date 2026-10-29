<?php

declare(strict_types=1);

namespace Pirainuthal\TallyExport\Xml;

/**
 * Prepares user text for XML. XMLWriter already escapes &, <, > (and quotes
 * inside attributes); this class removes what XML 1.0 cannot carry at all.
 */
final class Text
{
    private function __construct() {}

    public static function clean(string $value): string
    {
        // Replace invalid UTF-8 sequences instead of letting XMLWriter emit broken output.
        $value = mb_scrub($value, 'UTF-8');

        // XML 1.0 forbids C0 control characters other than TAB, LF and CR.
        return (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $value);
    }

    public static function yesNo(bool $value): string
    {
        return $value ? 'Yes' : 'No';
    }
}
