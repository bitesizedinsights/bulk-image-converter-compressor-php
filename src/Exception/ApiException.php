<?php

declare(strict_types=1);

namespace ImageConverter\Exception;

use Exception;
use Throwable;

class ApiException extends Exception
{
    /**
     * @param  string|null  $runId  ID of the Actor run the error relates to, when known.
     * @param  string|null  $statusMessage  The Actor run's own status message (raw, at most 2000 characters), when known.
     */
    public function __construct(
        string $message = '',
        int $code = 0,
        ?Throwable $previous = null,
        public readonly ?string $runId = null,
        public readonly ?string $statusMessage = null,
    ) {
        parent::__construct($message, $code, $previous);
    }
}
