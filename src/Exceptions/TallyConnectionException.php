<?php

declare(strict_types=1);

namespace Pirainuthal\TallyExport\Exceptions;

/**
 * Tally could not be reached or answered with a non-2xx HTTP status.
 */
class TallyConnectionException extends TallyException {}
