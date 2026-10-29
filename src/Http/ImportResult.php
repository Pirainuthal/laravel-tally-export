<?php

declare(strict_types=1);

namespace Pirainuthal\TallyExport\Http;

use Pirainuthal\TallyExport\Exceptions\TallyImportException;
use Pirainuthal\TallyExport\Exceptions\TallyResponseException;
use SimpleXMLElement;

/**
 * Counters from Tally's import response.
 *
 * Handles both the bare <RESPONSE><CREATED>.. form and the
 * <ENVELOPE>..<BODY><DATA><IMPORTRESULT><CREATED>.. form.
 */
final class ImportResult
{
    /**
     * @param  list<string>  $lineErrors
     */
    public function __construct(
        public readonly int $created = 0,
        public readonly int $altered = 0,
        public readonly int $deleted = 0,
        public readonly int $combined = 0,
        public readonly int $ignored = 0,
        public readonly int $errors = 0,
        public readonly int $cancelled = 0,
        public readonly int $exceptions = 0,
        public readonly ?int $lastVoucherId = null,
        public readonly array $lineErrors = [],
        public readonly string $raw = '',
    ) {}

    public static function fromXml(string $body): self
    {
        $xml = self::load($body);

        $count = static function (string $tag) use ($xml): int {
            $nodes = $xml->xpath('//'.$tag);

            return is_array($nodes) && isset($nodes[0]) ? (int) trim((string) $nodes[0]) : 0;
        };

        $hasCounters = false;
        foreach (['CREATED', 'ALTERED', 'ERRORS', 'LINEERROR'] as $tag) {
            $nodes = $xml->xpath('//'.$tag);
            $hasCounters = $hasCounters || (is_array($nodes) && $nodes !== []);
        }

        if (! $hasCounters) {
            throw new TallyResponseException('Tally response has no CREATED/ALTERED/ERRORS counters.', $body);
        }

        $lineErrors = [];
        foreach ($xml->xpath('//LINEERROR') ?: [] as $node) {
            $lineErrors[] = trim((string) $node);
        }

        $lastVchNodes = $xml->xpath('//LASTVCHID');
        $lastVch = is_array($lastVchNodes) && isset($lastVchNodes[0]) ? trim((string) $lastVchNodes[0]) : '';

        return new self(
            created: $count('CREATED'),
            altered: $count('ALTERED'),
            deleted: $count('DELETED'),
            combined: $count('COMBINED'),
            ignored: $count('IGNORED'),
            // A LINEERROR without an ERRORS counter still means the import failed.
            errors: max($count('ERRORS'), count($lineErrors)),
            cancelled: $count('CANCELLED'),
            exceptions: $count('EXCEPTIONS'),
            lastVoucherId: ctype_digit($lastVch) && $lastVch !== '0' ? (int) $lastVch : null,
            lineErrors: $lineErrors,
            raw: $body,
        );
    }

    public function successful(): bool
    {
        return $this->errors === 0 && $this->exceptions === 0;
    }

    /** @throws TallyImportException */
    public function throwIfFailed(): self
    {
        if (! $this->successful()) {
            throw new TallyImportException($this);
        }

        return $this;
    }

    private static function load(string $body): SimpleXMLElement
    {
        if (str_starts_with($body, "\xFF\xFE") || str_starts_with($body, "\xFE\xFF")) {
            $body = (string) mb_convert_encoding(substr($body, 2), 'UTF-8', str_starts_with($body, "\xFF\xFE") ? 'UTF-16LE' : 'UTF-16BE');
        }

        $body = trim(str_starts_with($body, "\xEF\xBB\xBF") ? substr($body, 3) : $body);
        // A UTF-16 declaration would contradict the converted UTF-8 text.
        $body = (string) preg_replace('/^<\?xml[^>]*\?>/', '', $body);

        if ($body === '') {
            throw new TallyResponseException('Tally returned an empty response.');
        }

        $previous = libxml_use_internal_errors(true);
        try {
            $xml = simplexml_load_string($body, SimpleXMLElement::class, LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if ($xml === false) {
            throw new TallyResponseException('Tally response is not well-formed XML.', $body);
        }

        return $xml;
    }
}
