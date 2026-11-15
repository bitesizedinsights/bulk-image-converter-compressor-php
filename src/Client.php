<?php

declare(strict_types=1);

namespace ImageConverter;

use Closure;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use ImageConverter\DTO\ConvertedImage;
use ImageConverter\Exception\ApiException;
use ImageConverter\Exception\MissingApiKeyException;
use ImageConverter\Exception\RateLimitException;
use InvalidArgumentException;
use JsonException;
use Throwable;

final class Client
{
    private const MAX_IMAGES = 1000;

    private const MAX_DIMENSION = 16000;

    private const FAILED_STATUSES = ['FAILED', 'ABORTED', 'TIMED-OUT'];

    private const RUNNING_STATUSES = ['READY', 'RUNNING'];

    /** Longest wait Apify allows per request. */
    private const MAX_WAIT_SECONDS = 60;

    private readonly Config $config;

    private readonly ClientInterface $http;

    private ?string $apiKey = null;

    /** @var Closure(float): void */
    private readonly Closure $sleeper;

    /**
     * @param  (Closure(float): void)|null  $sleeper  Waits the given seconds between retries; replaced in tests.
     */
    public function __construct(?Config $config = null, ?ClientInterface $http = null, ?Closure $sleeper = null)
    {
        $this->config = $config ?? new Config;
        $this->http = $http ?? new HttpClient;
        $this->sleeper = $sleeper ?? static function (float $seconds): void {
            usleep((int) round($seconds * 1_000_000));
        };
    }

    /**
     * Set the Apify API key. Without it, `APIFY_API_TOKEN` or `APIFY_TOKEN` from the environment is used.
     */
    public function setApifyKey(string $apiKey): static
    {
        $this->apiKey = $apiKey;

        return $this;
    }

    /**
     * Convert, compress and resize images and return one result per image.
     *
     * Failed images come back as rows with status `failed`; they do not throw.
     *
     * @param  list<string>  $urls  Public http(s) image URLs, up to 25 MB each.
     * @param  OutputFormat  $outputFormat  Target format; `OutputFormat::Original` keeps each image's format.
     * @param  int  $quality  1 (smallest file) to 100 (best quality).
     * @param  int|null  $maxWidth  Shrink wider images to this many pixels, 0 to 16000. Never enlarges.
     * @param  int|null  $maxHeight  Shrink taller images to this many pixels, 0 to 16000. Never enlarges.
     * @param  bool|null  $keepAspectRatio  Fit inside the maximum size without distortion (Actor default: true).
     * @param  bool|null  $stripMetadata  Remove camera data and GPS, convert to sRGB (Actor default: true).
     * @param  string|null  $backgroundColor  Hex color like `#ffffff` that fills transparency for JPEG and BMP.
     * @param  list<string>  $uploadKeys  Keys of files you uploaded to a key-value store.
     * @param  string|null  $uploadStoreName  Name of the store holding the uploaded files; null = default store.
     * @return list<ConvertedImage>
     *
     * @throws InvalidArgumentException When an argument is outside the limits the Actor accepts.
     * @throws ApiException
     * @throws RateLimitException
     */
    public function convert(
        array $urls = [],
        OutputFormat $outputFormat = OutputFormat::WebP,
        int $quality = 80,
        ?int $maxWidth = null,
        ?int $maxHeight = null,
        ?bool $keepAspectRatio = null,
        ?bool $stripMetadata = null,
        ?string $backgroundColor = null,
        array $uploadKeys = [],
        ?string $uploadStoreName = null,
    ): array {
        $this->validate($urls, $uploadKeys, $quality, $maxWidth, $maxHeight, $backgroundColor, $uploadStoreName);

        $input = [
            'outputFormat' => $outputFormat->value,
            'quality' => $quality,
        ];

        $optional = [
            'urls' => $urls === [] ? null : $urls,
            'uploadKeys' => $uploadKeys === [] ? null : $uploadKeys,
            'uploadStoreName' => $uploadStoreName,
            'maxWidth' => $maxWidth,
            'maxHeight' => $maxHeight,
            'keepAspectRatio' => $keepAspectRatio,
            'stripMetadata' => $stripMetadata,
            'backgroundColor' => $backgroundColor,
        ];

        foreach ($optional as $key => $value) {
            if ($value !== null) {
                $input[$key] = $value;
            }
        }

        $run = $this->startRun($input);
        $run = $this->awaitRun($run);

        return $this->fetchImages($this->datasetId($run));
    }

    /**
     * @param  list<string>  $urls
     * @param  list<string>  $uploadKeys
     */
    private function validate(
        array $urls,
        array $uploadKeys,
        int $quality,
        ?int $maxWidth,
        ?int $maxHeight,
        ?string $backgroundColor,
        ?string $uploadStoreName = null,
    ): void {
        $total = count($urls) + count($uploadKeys);

        if ($total === 0) {
            throw new InvalidArgumentException('Provide at least one image URL or upload key');
        }

        if ($total > self::MAX_IMAGES) {
            throw new InvalidArgumentException('At most '.self::MAX_IMAGES.' images per run are allowed');
        }

        foreach (['urls' => [$urls, 2048], 'uploadKeys' => [$uploadKeys, 256]] as $name => [$items, $max]) {
            foreach ($items as $i => $item) {
                if ($this->length($item) > $max) {
                    throw new InvalidArgumentException("{$name}[{$i}] must be at most {$max} characters long");
                }
            }
        }

        if ($uploadStoreName !== null && $this->length($uploadStoreName) > 63) {
            throw new InvalidArgumentException('uploadStoreName must be at most 63 characters long');
        }

        if ($quality < 1 || $quality > 100) {
            throw new InvalidArgumentException('Quality must be between 1 and 100');
        }

        foreach (['maxWidth' => $maxWidth, 'maxHeight' => $maxHeight] as $name => $value) {
            if ($value !== null && ($value < 0 || $value > self::MAX_DIMENSION)) {
                throw new InvalidArgumentException("{$name} must be between 0 and ".self::MAX_DIMENSION);
            }
        }

        if ($backgroundColor !== null && preg_match('/^#[0-9a-fA-F]{6}$/', $backgroundColor) !== 1) {
            throw new InvalidArgumentException('backgroundColor must be a hex color like #ffffff');
        }
    }

    /** Length in characters (code points), as the Actor's JSON schema counts them. */
    private function length(string $value): int
    {
        $count = preg_match_all('/./su', $value);

        return $count === false ? strlen($value) : $count;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<mixed>
     */
    private function startRun(array $input): array
    {
        $actor = str_replace('/', '~', $this->config->actorId);
        $result = $this->request('POST', "/acts/{$actor}/runs", ['json' => $input]);

        return $this->runData($result);
    }

    /**
     * @param  array<mixed>  $run
     * @return array<mixed>
     */
    private function awaitRun(array $run): array
    {
        $deadline = time() + $this->config->timeout;

        while (true) {
            $status = $run['status'] ?? null;

            if ($status === 'SUCCEEDED') {
                return $run;
            }

            if (is_string($status) && in_array($status, self::FAILED_STATUSES, true)) {
                throw new ApiException("Actor run ended with status {$status}");
            }

            if (! is_string($status) || ! in_array($status, self::RUNNING_STATUSES, true)) {
                throw new ApiException('Invalid API response: missing or unknown run status');
            }

            $remaining = $deadline - time();

            if ($remaining <= 0) {
                $runId = is_string($run['id'] ?? null) ? $run['id'] : null;
                $message = "Actor run did not finish within {$this->config->timeout} seconds";

                if ($runId !== null && $this->tryAbort($runId)) {
                    $message .= ' and was aborted';
                }

                throw new ApiException($message, runId: $runId);
            }

            $runId = $run['id'] ?? null;

            if (! is_string($runId)) {
                throw new ApiException('Invalid API response: missing run ID');
            }

            $result = $this->request('GET', "/actor-runs/{$runId}", [
                'query' => ['waitForFinish' => min(self::MAX_WAIT_SECONDS, $remaining)],
            ]);
            $run = $this->runData($result);
        }
    }

    /**
     * Aborts a run on a best-effort basis after the deadline expired: short timeout, no retries, errors swallowed.
     */
    private function tryAbort(string $runId): bool
    {
        try {
            $this->request('POST', "/actor-runs/{$runId}/abort", [], min(10, $this->config->requestTimeout), 0);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Aborts an Actor run, for example one that is no longer needed. The run keeps its data, its status becomes ABORTED.
     *
     * @param  string  $runId  The run ID, as found in `ApiException::$runId`.
     *
     * @throws ApiException
     */
    public function abort(string $runId): void
    {
        $this->request('POST', '/actor-runs/'.rawurlencode($runId).'/abort');
    }

    /**
     * @param  array<mixed>  $run
     */
    private function datasetId(array $run): string
    {
        $datasetId = $run['defaultDatasetId'] ?? null;

        if (! is_string($datasetId) || $datasetId === '') {
            throw new ApiException('Invalid API response: missing dataset ID');
        }

        return $datasetId;
    }

    /**
     * @return list<ConvertedImage>
     */
    private function fetchImages(string $datasetId): array
    {
        $items = $this->request('GET', "/datasets/{$datasetId}/items", ['query' => ['format' => 'json']]);

        $images = [];

        foreach (array_values($items) as $index => $item) {
            try {
                if (! is_array($item)) {
                    throw new InvalidArgumentException('Item must be an object');
                }

                $images[] = ConvertedImage::fromArray($item);
            } catch (InvalidArgumentException $e) {
                throw new ApiException("Invalid dataset item at index {$index}: ".$e->getMessage(), 0, $e);
            }
        }

        return $images;
    }

    /**
     * @param  array<mixed>  $result
     * @return array<mixed>
     */
    private function runData(array $result): array
    {
        $data = $result['data'] ?? null;

        if (! is_array($data)) {
            throw new ApiException('Invalid API response: missing run data');
        }

        return $data;
    }

    /**
     * @throws MissingApiKeyException
     */
    private function resolveApiKey(): string
    {
        $key = $this->apiKey ?: (getenv('APIFY_API_TOKEN') ?: getenv('APIFY_TOKEN'));

        if (! is_string($key) || $key === '') {
            throw new MissingApiKeyException;
        }

        return $key;
    }

    /**
     * Sends a request and retries 429, 5xx and connection failures with exponential backoff. Starting a run (POST) is
     * only repeated after a 429 or a failed connection, because after a 5xx or a timeout the run may already exist and
     * a repeat would bill it twice.
     *
     * @param  array<string, mixed>  $options
     * @return array<mixed>
     *
     * @throws ApiException
     * @throws RateLimitException
     */
    private function request(
        string $method,
        string $path,
        array $options = [],
        ?int $timeout = null,
        ?int $maxRetries = null,
    ): array {
        $maxRetries ??= $this->config->maxRetries;
        $options['headers'] = [
            'Authorization' => 'Bearer '.$this->resolveApiKey(),
            'Accept' => 'application/json',
        ];
        $options['timeout'] = $timeout ?? $this->config->requestTimeout;

        for ($attempt = 0; ; $attempt++) {
            try {
                $response = $this->http->request($method, rtrim($this->config->baseUrl, '/').$path, $options);
                $decoded = json_decode($response->getBody()->getContents(), true, 512, JSON_THROW_ON_ERROR);
            } catch (BadResponseException $e) {
                $status = $e->getResponse()->getStatusCode();
                $retryable = $status === 429 || ($status >= 500 && $method !== 'POST');
                $delay = $retryable && $attempt < $maxRetries
                    ? $this->retryDelay($attempt, $status === 429 ? $this->retryAfter($e) : null)
                    : null;

                if ($delay === null) {
                    $this->handleBadResponse($e);
                }

                ($this->sleeper)($delay);

                continue;
            } catch (JsonException $e) {
                throw new ApiException('Invalid API response: malformed JSON', 0, $e);
            } catch (GuzzleException $e) {
                // A failed connection never reached the server, so it is safe to repeat even for POST.
                $retryable = $e instanceof ConnectException || $method !== 'POST';

                if ($retryable && $attempt < $maxRetries) {
                    ($this->sleeper)($this->retryDelay($attempt, null) ?? 0.0);

                    continue;
                }

                throw new ApiException('API request failed: '.$e->getMessage(), 0, $e);
            }

            if (! is_array($decoded)) {
                throw new ApiException('Invalid API response: unexpected JSON');
            }

            return $decoded;
        }
    }

    /**
     * Seconds to wait before the next retry: exponential from the base delay with 50 to 100 percent jitter, at least
     * the server's `Retry-After`. Null when the server asks for a longer wait than the configured maximum.
     */
    private function retryDelay(int $attempt, ?int $retryAfter): ?float
    {
        if ($retryAfter !== null && $retryAfter > $this->config->retryMaxDelay) {
            return null;
        }

        $backoff = min($this->config->retryMaxDelay, $this->config->retryBaseDelay * (2 ** $attempt));
        $jittered = $backoff * (random_int(500, 1000) / 1000);

        return max($jittered, (float) ($retryAfter ?? 0));
    }

    /**
     * Seconds from a `Retry-After` header, given either as a number or as an HTTP date. Null when missing or invalid.
     */
    private function retryAfter(BadResponseException $e): ?int
    {
        $value = trim($e->getResponse()->getHeaderLine('Retry-After'));

        if ($value === '') {
            return null;
        }

        if (ctype_digit($value)) {
            return (int) $value;
        }

        $timestamp = strtotime($value);

        return $timestamp === false ? null : max(0, $timestamp - time());
    }

    private function handleBadResponse(BadResponseException $e): never
    {
        $response = $e->getResponse();

        if ($response->getStatusCode() === 429) {
            throw new RateLimitException('Rate limit exceeded', $this->retryAfter($e) ?? 60);
        }

        throw new ApiException(
            "API request failed with status {$response->getStatusCode()}: ".$e->getMessage(),
            $response->getStatusCode(),
            $e,
        );
    }
}
