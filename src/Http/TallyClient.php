<?php

declare(strict_types=1);

namespace Pirainuthal\TallyExport\Http;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Pirainuthal\TallyExport\Exceptions\TallyConnectionException;

/**
 * Posts an import envelope to Tally's built-in HTTP server
 * (Tally Prime: F1 > Settings > Connectivity, "TallyPrime acts as Server", default port 9000).
 */
final class TallyClient
{
    public function __construct(
        private readonly HttpFactory $http,
        private readonly string $url = 'http://localhost:9000',
        private readonly int $timeout = 30,
    ) {}

    public function push(string $xml): ImportResult
    {
        try {
            $response = $this->http
                ->withBody($xml, 'text/xml; charset=utf-8')
                ->timeout($this->timeout)
                ->post($this->url);
        } catch (ConnectionException $e) {
            throw new TallyConnectionException(sprintf(
                'Could not reach Tally at %s. Is Tally running with the HTTP server (ODBC/Connectivity) enabled? %s',
                $this->url,
                $e->getMessage(),
            ), 0, $e);
        }

        if (! $response->successful()) {
            throw new TallyConnectionException(sprintf('Tally at %s answered HTTP %d.', $this->url, $response->status()));
        }

        return ImportResult::fromXml($response->body());
    }

    public function url(): string
    {
        return $this->url;
    }
}
