<?php

declare(strict_types=1);

namespace Pirainuthal\TallyExport\Exceptions;

/**
 * Tally answered, but the body is not a recognisable import response.
 */
class TallyResponseException extends TallyException
{
    public function __construct(string $message, public readonly string $body = '')
    {
        parent::__construct($message);
    }
}
