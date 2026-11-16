<?php

declare(strict_types=1);

namespace ImageConverter\Exception;

use Throwable;

/**
 * HTTP 402: the Apify account reached its monthly usage or spending limit. Never retried.
 */
final class SpendingLimitException extends ApiException
{
    /**
     * @param  string|null  $errorType  Apify's error `type` from the response body, null when it cannot be read.
     */
    public function __construct(
        string $message = 'Apify usage or spending limit reached',
        ?Throwable $previous = null,
        ?string $runId = null,
        ?string $statusMessage = null,
        public readonly ?string $errorType = null,
    ) {
        parent::__construct($message, 402, $previous, $runId, $statusMessage);
    }
}
