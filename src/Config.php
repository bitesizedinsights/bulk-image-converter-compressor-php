<?php

declare(strict_types=1);

namespace ImageConverter;

use InvalidArgumentException;

final readonly class Config
{
    /**
     * @param  int  $timeout  Maximum seconds to wait for a run to finish. Covers the run start, polling and retries.
     * @param  int  $requestTimeout  Timeout in seconds of a single HTTP request.
     * @param  int  $maxRetries  How often a failed request is repeated (0 turns retrying off). Rate limits (429), server
     *                           errors (5xx) and connection failures are retried; see the README for the exact rules.
     * @param  float  $retryBaseDelay  Seconds before the first retry; doubles with each further retry, with jitter.
     * @param  float  $retryMaxDelay  Longest wait before a retry. A `Retry-After` above this is not waited out.
     * @param  int|null  $runTimeout  Apify's own time limit of the run in seconds, sent as the `timeout` query of the
     *                                run start. Null = use `$timeout`, so a run cannot outlive the client's wait.
     *                                0 = send nothing and keep the Actor's own limit. Overridable per `convert()` call.
     *
     * @throws InvalidArgumentException When `$runTimeout` is negative.
     */
    public function __construct(
        public string $actorId = 'bite-sized-insights/bulk-image-converter-compressor-and-resizer',
        public string $baseUrl = 'https://api.apify.com/v2',
        public int $timeout = 600,
        public int $requestTimeout = 90,
        public int $maxRetries = 3,
        public float $retryBaseDelay = 1.0,
        public float $retryMaxDelay = 30.0,
        public ?int $runTimeout = null,
    ) {
        if ($runTimeout !== null && $runTimeout < 0) {
            throw new InvalidArgumentException(
                'runTimeout must be a positive number of seconds, or 0 for the Actor default'
            );
        }
    }
}
