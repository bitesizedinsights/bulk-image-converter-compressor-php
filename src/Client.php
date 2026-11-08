<?php

declare(strict_types=1);

namespace ImageConverter;

use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\GuzzleException;
use ImageConverter\DTO\ConvertedImage;
use ImageConverter\Exception\ApiException;
use ImageConverter\Exception\RateLimitException;
use InvalidArgumentException;
use JsonException;

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

    public function __construct(string $apiToken, ?Config $config = null, ?ClientInterface $http = null)
    {
        $this->config = $config ?? new Config($apiToken);
        $this->http = $http ?? new HttpClient;
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
        $this->validate($urls, $uploadKeys, $quality, $maxWidth, $maxHeight, $backgroundColor);

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
    ): void {
        $total = count($urls) + count($uploadKeys);

        if ($total === 0) {
            throw new InvalidArgumentException('Provide at least one image URL or upload key');
        }

        if ($total > self::MAX_IMAGES) {
            throw new InvalidArgumentException('At most '.self::MAX_IMAGES.' images per run are allowed');
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
                throw new ApiException("Actor run did not finish within {$this->config->timeout} seconds");
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
     * @param  array<string, mixed>  $options
     * @return array<mixed>
     *
     * @throws ApiException
     * @throws RateLimitException
     */
    private function request(string $method, string $path, array $options = []): array
    {
        $options['headers'] = [
            'Authorization' => 'Bearer '.$this->config->apiToken,
            'Accept' => 'application/json',
        ];
        $options['timeout'] = $this->config->requestTimeout;

        try {
            $response = $this->http->request($method, rtrim($this->config->baseUrl, '/').$path, $options);
            $decoded = json_decode($response->getBody()->getContents(), true, 512, JSON_THROW_ON_ERROR);
        } catch (BadResponseException $e) {
            $this->handleBadResponse($e);
        } catch (JsonException $e) {
            throw new ApiException('Invalid API response: malformed JSON', 0, $e);
        } catch (GuzzleException $e) {
            throw new ApiException('API request failed: '.$e->getMessage(), 0, $e);
        }

        if (! is_array($decoded)) {
            throw new ApiException('Invalid API response: unexpected JSON');
        }

        return $decoded;
    }

    private function handleBadResponse(BadResponseException $e): never
    {
        $response = $e->getResponse();

        if ($response->getStatusCode() === 429) {
            $retryAfter = (int) ($response->getHeaderLine('Retry-After') ?: 60);

            throw new RateLimitException('Rate limit exceeded', $retryAfter);
        }

        throw new ApiException(
            "API request failed with status {$response->getStatusCode()}: ".$e->getMessage(),
            $response->getStatusCode(),
            $e,
        );
    }
}
