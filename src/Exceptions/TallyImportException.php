<?php

declare(strict_types=1);

namespace Pirainuthal\TallyExport\Exceptions;

use Pirainuthal\TallyExport\Http\ImportResult;

/**
 * Tally processed the request but reported errors or exceptions.
 */
class TallyImportException extends TallyException
{
    public function __construct(public readonly ImportResult $result)
    {
        $details = $result->lineErrors === [] ? '' : ' First error: '.$result->lineErrors[0];

        parent::__construct(sprintf(
            'Tally import reported %d error(s) and %d exception(s); %d created, %d altered.%s',
            $result->errors,
            $result->exceptions,
            $result->created,
            $result->altered,
            $details,
        ));
    }
}
