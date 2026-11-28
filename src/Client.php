<?php

declare(strict_types=1);

namespace ImageConverter;

use Closure;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\GuzzleException;
use ImageConverter\DTO\ConvertedImage;
use ImageConverter\DTO\RetryEvent;
use ImageConverter\DTO\Run;
use ImageConverter\Exception\ApiException;
use ImageConverter\Exception\BatchException;
use ImageConverter\Exception\MissingApiKeyException;
use ImageConverter\Exception\RateLimitException;
use ImageConverter\Exception\SpendingLimitException;
use ImageConverter\Support\AtomicFile;
use ImageConverter\Support\Downloader;
use ImageConverter\Support\Inputs;
use ImageConverter\Support\Retrier;
use ImageConverter\Support\UploadFile;
use InvalidArgumentException;
use JsonException;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Throwable;

final class Client
{
    /** Default size limit of `download()` and `save()` in bytes; uncompressed output formats can get large. */
    public const MAX_DOWNLOAD_BYTES = 256 * 1024 * 1024;

    private const MAX_IMAGES = 1000;

    private const MAX_DIMENSION = 16000;

    private const FAILED_STATUSES = ['FAILED', 'ABORTED', 'TIMED-OUT'];

    private const RUNNING_STATUSES = ['READY', 'RUNNING'];

    /** Further statuses `wait()` sits out: the run is ending but its final status is not set yet. */
    private const ENDING_STATUSES = ['ABORTING', 'TIMING-OUT'];

    /** Apify IDs are plain alphanumeric strings; nothing else may become part of a URL path. */
    private const RUN_ID_PATTERN = '/\A[A-Za-z0-9]{1,64}\z/';

    /** Minimum seconds for reading the results of a successful run, even when the deadline is nearly used up. */
    private const RESULTS_WINDOW_SECONDS = 30;

    /** Longest wait Apify allows per request. */
    private const MAX_WAIT_SECONDS = 60;

    private readonly Config $config;

    private readonly ClientInterface $http;

    private ?string $apiKey = null;

    /** Apify's internal ID of the configured Actor, once a run start or a lookup has told it. */
    private ?string $actorApiId = null;

    private readonly Retrier $retrier;

    /**
     * @param  (Closure(float): void)|null  $sleeper  Waits the given seconds between retries; replaced in tests.
     * @param  (Closure(RetryEvent): void)|null  $onRetry  Called before the wait of each retry, for logging.
     *                                                     What it throws is ignored.
     */
    public function __construct(
        ?Config $config = null,
        ?ClientInterface $http = null,
        ?Closure $sleeper = null,
        ?Closure $onRetry = null,
    ) {
        $this->config = $config ?? new Config;
        $this->http = $http ?? new HttpClient;
        $sleeper ??= static function (float $seconds): void {
            usleep((int) round($seconds * 1_000_000));
        };
        $this->retrier = new Retrier($this->config, $sleeper, $onRetry);
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
     * Convert, compress and resize images and return one result per processed image.
     *
     * Fewer rows than inputs come back on the free plan (first 3 images), with duplicate inputs (processed once),
     * at the spending limit or after a shutdown. `ConvertedImage::$index` is 1-based and refers to the Actor's
     * deduplicated list (URLs first, then upload keys). Use `Results::missingIndexes()`, `Results::unprocessed()` and
     * `Results::isComplete()` to find images without a row. A row with a `time_limit` error counts as processed.
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
     * @param  string|null  $uploadStore  ID of the store holding the uploaded files. Selecting a store this way is what
     *                                    gives the run read access to it; it needs `uploadKeys` and takes precedence
     *                                    over `uploadStoreName`.
     * @param  int|null  $runTimeout  Apify run time limit in seconds for this call, 0 = the Actor's own limit; null =
     *                                `Config::$runTimeout`, which itself defaults to `Config::$timeout`.
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
        ?int $runTimeout = null,
        ?string $uploadStore = null,
    ): array {
        $input = $this->buildInput(
            $urls,
            $outputFormat,
            $quality,
            $maxWidth,
            $maxHeight,
            $keepAspectRatio,
            $stripMetadata,
            $backgroundColor,
            $uploadKeys,
            $uploadStoreName,
            $runTimeout,
            $uploadStore,
        );

        return $this->execute($input, $runTimeout);
    }

    /**
     * Like `convert()` for any number of images: they are split into batches of at most `$batchSize` (URLs and upload
     * keys counted together), and each batch is a separate Actor run (billed on its own), run one after another.
     *
     * URLs and upload keys are first deduplicated over the whole input, the way the Actor does it within a run
     * (control characters and surrounding whitespace stripped, a scheme-less URL gets `https://`, empty values
     * dropped, the first occurrence wins), so no image is converted twice. `ConvertedImage::$index` is rewritten to
     * the 1-based position in that deduplicated list of the whole input (URLs first, then upload keys), so the
     * `Results` helpers work on the complete input. Results come back batch by batch, within a batch in the order of
     * the run's dataset. When nothing is left to convert, an empty list is returned without any request.
     *
     * Each run stores its files in its own default key-value store: use `outputUrl`. `outputKey` is not rewritten;
     * the number it starts with counts within the batch, and keys of different batches can be equal.
     *
     * When a batch returns fewer rows than it has images (free plan, spending limit, shutdown), no further batch is
     * started, so a limit is not run into with more billed runs. Use `Results::unprocessed()` with the whole input to
     * find what is left. Failed rows, including `time_limit`, count as rows.
     *
     * `Config::$timeout` and the run timeout apply to each batch, not to the whole call. A full batch of 1000 images
     * may not finish within the default of 600 seconds: raise the timeout or lower `$batchSize`.
     *
     * All arguments are validated before the first run is started. For the other parameters see `convert()`.
     *
     * @param  array<int|string, string>  $urls  Any number of URLs.
     * @param  array<int|string, string>  $uploadKeys  Any number of upload keys.
     * @param  int  $batchSize  Images per run, 1 to 1000.
     * @return list<ConvertedImage>
     *
     * @throws InvalidArgumentException when an argument is invalid; nothing was started
     * @throws MissingApiKeyException
     * @throws BatchException when a batch fails for any other reason; it holds the results of the earlier batches
     */
    public function convertMany(
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
        ?int $runTimeout = null,
        int $batchSize = self::MAX_IMAGES,
        ?string $uploadStore = null,
    ): array {
        if ($batchSize < 1 || $batchSize > self::MAX_IMAGES) {
            throw new InvalidArgumentException('batchSize must be 1 to '.self::MAX_IMAGES.", got {$batchSize}");
        }

        $this->validateRunTimeout($runTimeout);
        $this->validateItems($urls, $uploadKeys);
        $this->validateOptions($quality, $maxWidth, $maxHeight, $backgroundColor, $uploadStoreName);
        $this->validateUploadStore($uploadStore, $uploadKeys);

        // [kind, original key, original string] of every first occurrence, in the Actor's order.
        $items = [];

        foreach (['urls' => $urls, 'uploadKeys' => $uploadKeys] as $kind => $values) {
            foreach (Inputs::unique($values, $kind === 'urls') as $key => $unused) {
                $items[] = [$kind, $key, $values[$key]];
            }
        }

        $batches = array_chunk($items, $batchSize);
        $results = [];
        $offset = 0;

        foreach ($batches as $number => $batch) {
            $runId = null;
            $inputs = $this->byKind($batch);

            try {
                $input = $this->input(array_values($inputs['urls']), array_values($inputs['uploadKeys']), [
                    'outputFormat' => $outputFormat->value,
                    'quality' => $quality,
                ], [
                    'uploadStoreName' => $uploadStoreName,
                    // The Actor fails a run that names a store without upload keys.
                    'uploadStore' => $inputs['uploadKeys'] === [] ? null : $uploadStore,
                    'maxWidth' => $maxWidth,
                    'maxHeight' => $maxHeight,
                    'keepAspectRatio' => $keepAspectRatio,
                    'stripMetadata' => $stripMetadata,
                    'backgroundColor' => $backgroundColor,
                ]);
                $images = $this->reindex($this->execute($input, $runTimeout, $runId), $offset, count($batch));
            } catch (Throwable $e) {
                // Without a key nothing was sent; there is nothing to preserve.
                if ($e instanceof MissingApiKeyException && $results === []) {
                    throw $e;
                }

                $runId ??= $e instanceof ApiException ? $e->runId : null;

                throw new BatchException(
                    $e,
                    $results,
                    $number + 1,
                    count($batches),
                    $inputs,
                    $this->byKind(array_slice($items, $offset + count($batch))),
                    $runId,
                );
            }

            array_push($results, ...$images);
            $offset += count($batch);

            if (count($images) < count($batch)) {
                break;
            }
        }

        return $results;
    }

    /**
     * @param  list<array{string, int|string, string}>  $items
     * @return array{urls: array<int|string, string>, uploadKeys: array<int|string, string>}
     */
    private function byKind(array $items): array
    {
        $result = ['urls' => [], 'uploadKeys' => []];

        foreach ($items as [$kind, $key, $value]) {
            $result[$kind === 'urls' ? 'urls' : 'uploadKeys'][$key] = $value;
        }

        return $result;
    }

    /**
     * Turns each row's index within its run into the index within the whole input.
     *
     * @param  list<ConvertedImage>  $images
     * @param  int  $offset  Images of all earlier batches.
     * @param  int  $size  Images of this batch.
     * @return list<ConvertedImage>
     *
     * @throws ApiException when a row has an index its batch cannot have
     */
    private function reindex(array $images, int $offset, int $size): array
    {
        return array_map(static function (ConvertedImage $image) use ($offset, $size): ConvertedImage {
            if ($image->index < 1 || $image->index > $size) {
                throw new ApiException("Invalid dataset item: index {$image->index} is outside 1 to {$size}");
            }

            return ConvertedImage::fromArray(['index' => $offset + $image->index] + $image->toArray());
        }, $images);
    }

    /**
     * Start a conversion run and return at once; the run keeps going on Apify. Takes the arguments of `convert()`.
     * Keep `$run->id`, then collect the rows with `wait()` and `results()`, in this or a later process.
     *
     * A run start is billed, so it is only repeated when it provably had no effect (HTTP 429 or a request that never
     * left). After a server error, a timeout or an unreadable response a run may exist: check before calling again.
     *
     * @param  list<string>  $urls
     * @param  list<string>  $uploadKeys
     * @param  int|null  $runTimeout  Apify run time limit in seconds for this call, 0 = the Actor's own limit; null =
     *                                `Config::$runTimeout`, which itself defaults to `Config::$timeout`.
     *
     * @throws InvalidArgumentException When an argument is outside the limits the Actor accepts.
     * @throws ApiException `$runId` is set when the run was created but the response could not be fully read.
     * @throws RateLimitException
     * @throws SpendingLimitException
     */
    public function start(
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
        ?int $runTimeout = null,
        ?string $uploadStore = null,
    ): Run {
        $input = $this->buildInput(
            $urls,
            $outputFormat,
            $quality,
            $maxWidth,
            $maxHeight,
            $keepAspectRatio,
            $stripMetadata,
            $backgroundColor,
            $uploadKeys,
            $uploadStoreName,
            $runTimeout,
            $uploadStore,
        );

        $run = $this->parseRun($this->startRun($input, $runTimeout, microtime(true) + $this->config->timeout));
        $this->actorApiId ??= $run->actId;

        return $run;
    }

    /**
     * Validates the arguments of one run and builds the Actor input.
     *
     * @param  list<string>  $urls
     * @param  list<string>  $uploadKeys
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException
     */
    private function buildInput(
        array $urls,
        OutputFormat $outputFormat,
        int $quality,
        ?int $maxWidth,
        ?int $maxHeight,
        ?bool $keepAspectRatio,
        ?bool $stripMetadata,
        ?string $backgroundColor,
        array $uploadKeys,
        ?string $uploadStoreName,
        ?int $runTimeout,
        ?string $uploadStore,
    ): array {
        $this->validateRunTimeout($runTimeout);
        $this->validateCount($urls, $uploadKeys);
        $this->validateItems($urls, $uploadKeys);
        $this->validateOptions($quality, $maxWidth, $maxHeight, $backgroundColor, $uploadStoreName);
        $this->validateUploadStore($uploadStore, $uploadKeys);

        return $this->input($urls, $uploadKeys, [
            'outputFormat' => $outputFormat->value,
            'quality' => $quality,
        ], [
            'uploadStoreName' => $uploadStoreName,
            'uploadStore' => $uploadStore,
            'maxWidth' => $maxWidth,
            'maxHeight' => $maxHeight,
            'keepAspectRatio' => $keepAspectRatio,
            'stripMetadata' => $stripMetadata,
            'backgroundColor' => $backgroundColor,
        ]);
    }

    /**
     * @param  array<mixed>  $urls
     * @param  array<mixed>  $uploadKeys
     * @param  array<string, mixed>  $required
     * @param  array<string, mixed>  $optional  Left out of the input when null.
     * @return array<string, mixed>
     */
    private function input(array $urls, array $uploadKeys, array $required, array $optional): array
    {
        $input = $required;
        $optional = [
            'urls' => $urls === [] ? null : $urls,
            'uploadKeys' => $uploadKeys === [] ? null : $uploadKeys,
        ] + $optional;

        foreach ($optional as $key => $value) {
            if ($value !== null) {
                $input[$key] = $value;
            }
        }

        return $input;
    }

    /**
     * Runs the Actor once and returns its rows.
     *
     * @param  array<string, mixed>  $input
     * @param  string|null  $runId  Set to the run's ID as soon as it is started.
     * @return list<ConvertedImage>
     */
    private function execute(array $input, ?int $runTimeout, ?string &$runId = null): array
    {
        $deadline = microtime(true) + $this->config->timeout;
        $run = $this->startRun($input, $runTimeout, $deadline);
        $runId = is_string($run['id'] ?? null) ? $run['id'] : null;
        $run = $this->awaitRun($run, $deadline, $this->config->timeout, ownRun: true);

        $datasetId = $this->datasetId($run);

        return $this->fetchImages($datasetId, max($deadline, microtime(true) + self::RESULTS_WINDOW_SECONDS));
    }

    private function validateRunTimeout(?int $runTimeout): void
    {
        if ($runTimeout !== null && $runTimeout < 0) {
            throw new InvalidArgumentException(
                'runTimeout must be a positive number of seconds, or 0 for the Actor default'
            );
        }
    }

    /**
     * @param  array<mixed>  $urls
     * @param  array<mixed>  $uploadKeys
     */
    private function validateCount(array $urls, array $uploadKeys): void
    {
        $total = count($urls) + count($uploadKeys);

        if ($total === 0) {
            throw new InvalidArgumentException('Provide at least one image URL or upload key');
        }

        if ($total > self::MAX_IMAGES) {
            throw new InvalidArgumentException('At most '.self::MAX_IMAGES.' images per run are allowed');
        }
    }

    /**
     * @param  array<mixed>  $urls
     * @param  array<mixed>  $uploadKeys
     */
    private function validateItems(array $urls, array $uploadKeys): void
    {
        foreach (['urls' => [$urls, 2048], 'uploadKeys' => [$uploadKeys, 256]] as $name => [$items, $max]) {
            foreach ($items as $i => $item) {
                if (! is_string($item)) {
                    throw new InvalidArgumentException("{$name}[{$i}] must be a string");
                }

                if ($this->length($item) > $max) {
                    throw new InvalidArgumentException("{$name}[{$i}] must be at most {$max} characters long");
                }
            }
        }
    }

    private function validateOptions(
        int $quality,
        ?int $maxWidth,
        ?int $maxHeight,
        ?string $backgroundColor,
        ?string $uploadStoreName,
    ): void {
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

    /**
     * @param  array<mixed>  $uploadKeys
     */
    private function validateUploadStore(?string $uploadStore, array $uploadKeys): void
    {
        if ($uploadStore === null) {
            return;
        }

        if ($uploadStore === '' || $this->length($uploadStore) > 200) {
            throw new InvalidArgumentException('uploadStore must be 1 to 200 characters long');
        }

        if ($uploadKeys === []) {
            throw new InvalidArgumentException('uploadStore needs the record keys to convert in uploadKeys');
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
    private function startRun(array $input, ?int $runTimeout, float $deadline): array
    {
        $actor = str_replace('/', '~', $this->config->actorId);
        $options = ['json' => $input];
        $limit = $runTimeout ?? $this->config->runTimeout ?? $this->config->timeout;

        if ($limit > 0) {
            $options['query'] = ['timeout' => $limit];
        }

        $result = $this->request('POST', "/acts/{$actor}/runs", $options, deadline: $deadline);

        return $this->runData($result);
    }

    /**
     * @param  array<mixed>  $run
     * @param  int  $limit  The seconds the deadline stands for, as named in the timeout error.
     * @param  bool  $ownRun  True within `convert()`, which started the run: it is aborted when the time is up. False
     *                        in `wait()`, which leaves it running and also sits out ABORTING and TIMING-OUT.
     * @return array<mixed>
     */
    private function awaitRun(array $run, float $deadline, int $limit, bool $ownRun): array
    {
        while (true) {
            $status = $run['status'] ?? null;

            if ($status === 'SUCCEEDED') {
                return $run;
            }

            if (is_string($status) && in_array($status, self::FAILED_STATUSES, true)) {
                $statusMessage = $this->statusMessage($run);
                $message = "Actor run ended with status {$status}";

                if ($statusMessage !== null) {
                    $message .= ': '.$this->shorten($statusMessage);
                }

                throw new ApiException(
                    $message,
                    runId: is_string($run['id'] ?? null) ? $run['id'] : null,
                    statusMessage: $statusMessage,
                );
            }

            if (! is_string($status) || ! $this->isPending($status, $ownRun)) {
                throw new ApiException('Invalid API response: missing or unknown run status');
            }

            $remaining = (int) ceil($deadline - microtime(true));

            if ($remaining <= 0) {
                $runId = is_string($run['id'] ?? null) ? $run['id'] : null;
                $message = "Actor run did not finish within {$limit} seconds";

                if ($ownRun && $runId !== null && $this->tryAbort($runId)) {
                    $message .= ' and was aborted';
                }

                throw new ApiException($message, runId: $runId, statusMessage: $this->statusMessage($run));
            }

            $runId = $run['id'] ?? null;

            if (! is_string($runId)) {
                throw new ApiException('Invalid API response: missing run ID');
            }

            $retrySkipped = false;

            try {
                $result = $this->request('GET', '/actor-runs/'.rawurlencode($runId), [
                    'query' => ['waitForFinish' => min(self::MAX_WAIT_SECONDS, $remaining)],
                ], deadline: $deadline, retrySkipped: $retrySkipped);
            } catch (ApiException $e) {
                // The deadline ends the wait here, so the run must not keep running and billing.
                if ($ownRun && ($retrySkipped || microtime(true) >= $deadline)) {
                    $this->tryAbort($runId);
                }

                throw $e;
            }

            $run = $this->runData($result);
        }
    }

    private function isPending(string $status, bool $ownRun): bool
    {
        return in_array($status, self::RUNNING_STATUSES, true)
            || (! $ownRun && in_array($status, self::ENDING_STATUSES, true));
    }

    /**
     * The run's statusMessage when it is a non-empty string, control characters removed, capped at 2000 characters.
     *
     * @param  array<mixed>  $run
     */
    private function statusMessage(array $run): ?string
    {
        $message = $run['statusMessage'] ?? null;

        if (! is_string($message)) {
            return null;
        }

        $message = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $message));

        return $message === '' ? null : mb_substr($message, 0, 2000);
    }

    private function shorten(string $text): string
    {
        return mb_strlen($text) > 500 ? mb_substr($text, 0, 497).'...' : $text;
    }

    /**
     * Aborts a run on a best-effort basis after the deadline expired: short timeout, no retries, errors swallowed.
     */
    private function tryAbort(string $runId): bool
    {
        try {
            $path = '/actor-runs/'.rawurlencode($runId).'/abort';
            $this->request('POST', $path, [], min(10, $this->config->requestTimeout), 0);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Asks Apify to stop a run, for example one that is no longer needed. The run keeps its data. Does not check which
     * Actor the run belongs to.
     *
     * @param  Run|string  $run  The run or its ID, as found in `ApiException::$runId`.
     * @return Run The run in its new state, usually ABORTING or ABORTED.
     *
     * @throws InvalidArgumentException when the run ID is not a valid Apify ID; nothing was sent
     * @throws ApiException
     * @throws RateLimitException
     * @throws SpendingLimitException
     */
    public function abort(Run|string $run): Run
    {
        $runId = $this->validRunId($run);
        $result = $this->request(
            'POST',
            "/actor-runs/{$runId}/abort",
            deadline: microtime(true) + $this->config->timeout,
        );

        return $this->parseRun($this->runData($result), $runId);
    }

    /**
     * Look a run of the configured Actor up once, without waiting for it. Any status is returned, none throws.
     *
     * @throws InvalidArgumentException when the run ID is not a valid Apify ID; nothing was sent
     * @throws ApiException when the run does not exist (code 404) or belongs to another Actor
     * @throws RateLimitException
     * @throws SpendingLimitException
     */
    public function getRun(string $runId): Run
    {
        $runId = $this->validRunId($runId);

        return $this->parseRun($this->fetchOwnRun($runId, microtime(true) + $this->config->timeout), $runId);
    }

    /**
     * Wait until a run has finished successfully. Accepts the run from `start()` or just its ID. Unlike `convert()`,
     * it never aborts the run: when the time is up the run keeps going and you can wait again or call `abort()`.
     *
     * @param  int|null  $timeout  Seconds to wait at most, 0 = check once; null = `Config::$timeout`.
     * @return Run The run with status SUCCEEDED.
     *
     * @throws InvalidArgumentException when the run ID is not a valid Apify ID or `$timeout` is negative
     * @throws ApiException when the run ends as FAILED, ABORTED or TIMED-OUT, does not finish in time, does not exist
     *                      or belongs to another Actor
     * @throws RateLimitException
     * @throws SpendingLimitException
     */
    public function wait(Run|string $run, ?int $timeout = null): Run
    {
        if ($timeout !== null && $timeout < 0) {
            throw new InvalidArgumentException('timeout must be 0 or a positive number of seconds');
        }

        $runId = $this->validRunId($run);
        $limit = $timeout ?? $this->config->timeout;
        $deadline = microtime(true) + $limit;
        // Without time to poll, an unfinished `Run` may be out of date, so it is looked up.
        if ($limit === 0 && $run instanceof Run && ! $run->isFinished()) {
            $run = $runId;
        }

        $data = $this->awaitRun($this->runState($run, $deadline), $deadline, $limit, ownRun: false);

        return $this->parseRun($data, $runId);
    }

    /**
     * Read the rows of a successful run, like `convert()` returns them. Does not wait: call `wait()` first. A `Run`
     * object is taken at its word, so pass the one `wait()` returned or the run ID, which is looked up again.
     *
     * @return list<ConvertedImage>
     *
     * @throws InvalidArgumentException when the run ID is not a valid Apify ID; nothing was sent
     * @throws ApiException when the run has not succeeded (unfinished, failed, aborted or timed out), does not exist
     *                      or belongs to another Actor, or the dataset cannot be mapped
     * @throws RateLimitException
     * @throws SpendingLimitException
     */
    public function results(Run|string $run): array
    {
        $runId = $this->validRunId($run);
        $deadline = microtime(true) + max($this->config->timeout, self::RESULTS_WINDOW_SECONDS);
        $current = $this->parseRun($this->runState($run, $deadline), $runId);

        if (! $current->isSuccessful()) {
            $hint = $current->isFinished() ? '' : ', call wait()';

            throw new ApiException(
                "Run {$runId} has status {$current->status->value}; results are only read after SUCCEEDED{$hint}",
                runId: $runId,
                statusMessage: $this->statusMessage($current->toArray()),
            );
        }

        return $this->fetchImages($this->datasetId($current->toArray()), $deadline);
    }

    /**
     * Get the ID of your key-value store with this name, creating it when there is none. Apify keeps named stores
     * until you delete them. Pass the ID to `uploadFile()` and as `uploadStore` to `convert()`.
     *
     * @param  string  $name  1 to 63 letters, digits and hyphens, no hyphen at either end.
     *
     * @throws InvalidArgumentException when the name is invalid; nothing was sent
     * @throws ApiException
     * @throws RateLimitException
     * @throws SpendingLimitException
     */
    public function getOrCreateStore(string $name): string
    {
        if (preg_match('/\A[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?\z/', $name) !== 1) {
            throw new InvalidArgumentException(
                'A store name must be 1 to 63 letters, digits and hyphens, with no hyphen at either end'
            );
        }

        $response = $this->storeRequest('POST', '/key-value-stores', ['query' => ['name' => $name]], $this->deadline());
        $decoded = json_decode((string) $response->getBody(), true);
        $data = is_array($decoded) ? ($decoded['data'] ?? null) : null;
        $id = is_array($data) ? ($data['id'] ?? null) : null;

        if (! is_string($id) || preg_match(self::RUN_ID_PATTERN, $id) !== 1) {
            throw new ApiException('Invalid API response: missing or invalid store ID');
        }

        return $id;
    }

    /**
     * Upload a local image into a key-value store, to convert it with `uploadKeys`. Redirects are never followed.
     *
     * An existing record is not replaced unless `overwrite` is true. That is checked right before the upload, which
     * Apify cannot do in one step: of two uploads to the same key at the same moment, the later one wins. The record
     * stays in the store until you delete it or the store's retention ends.
     *
     * @param  string  $path  A local file of 1 byte to 25 MB.
     * @param  string  $store  A store ID, or `username~store-name` (`~store-name` for your own).
     * @param  string|null  $key  Up to 256 characters of `a-zA-Z0-9!-_.'()`; null = the file name, with other
     *                            characters replaced by `-`.
     * @param  string|null  $contentType  `application/octet-stream` or an image type; null = from the file extension.
     * @return string The record key, for `uploadKeys`.
     *
     * @throws InvalidArgumentException when the file, store, key or content type is not allowed; nothing was sent
     * @throws ApiException when a request fails, or with code 409 when the key is taken
     * @throws RateLimitException
     * @throws SpendingLimitException
     */
    public function uploadFile(
        string $path,
        string $store,
        ?string $key = null,
        bool $overwrite = false,
        ?string $contentType = null,
    ): string {
        if (preg_match('/\A(?:[A-Za-z0-9_.-]*~)?[A-Za-z0-9-]{1,64}\z/', $store) !== 1) {
            throw new InvalidArgumentException('The store must be a store ID or username~store-name');
        }

        $content = UploadFile::content($path);
        $key = UploadFile::key($path, $key);
        $contentType = UploadFile::contentType($path, $contentType);
        $record = '/key-value-stores/'.rawurlencode($store).'/records/'.rawurlencode($key);
        $deadline = $this->deadline();

        if (! $overwrite && $this->recordExists($record, $deadline)) {
            throw new ApiException(
                "The store already has a record with the key {$key}, pass overwrite: true or another key",
                409,
            );
        }

        $this->storeRequest('PUT', $record, [
            'body' => $content,
            'headers' => ['Content-Type' => $contentType],
        ], $deadline);

        return $key;
    }

    /** The deadline of one store operation; like reading results it always gets a minimum window. */
    private function deadline(): float
    {
        return microtime(true) + max($this->config->timeout, self::RESULTS_WINDOW_SECONDS);
    }

    private function recordExists(string $record, float $deadline): bool
    {
        try {
            $this->storeRequest('HEAD', $record, [], $deadline);
        } catch (ApiException $e) {
            if ($e->getCode() === 404) {
                return false;
            }

            throw $e;
        }

        return true;
    }

    /**
     * A store request: unlike `request()` it follows no redirect and does not expect JSON.
     *
     * @param  array{query?: array<string, string>, body?: string, headers?: array<string, string>}  $options
     */
    private function storeRequest(string $method, string $path, array $options, float $deadline): ResponseInterface
    {
        $url = rtrim($this->config->baseUrl, '/').$path;
        $options['headers'] = ($options['headers'] ?? []) + [
            'Authorization' => 'Bearer '.$this->resolveApiKey(),
            'Accept' => 'application/json',
        ];
        $options['allow_redirects'] = false;

        for ($attempt = 0; ; $attempt++) {
            $options['timeout'] = $this->retrier->requestTimeout($this->config->requestTimeout, $deadline);

            try {
                $response = $this->http->request($method, $url, $options);
            } catch (BadResponseException $e) {
                if ($this->retrier->retry($e, $method, $path, $attempt, $deadline)) {
                    continue;
                }

                $this->handleBadResponse($e);
            } catch (GuzzleException $e) {
                if ($this->retrier->retry($e, $method, $path, $attempt, $deadline)) {
                    continue;
                }

                throw new ApiException('API request failed: '.$e->getMessage(), 0, $e);
            }

            $status = $response->getStatusCode();

            if ($status < 200 || $status >= 300) {
                throw new ApiException("API request failed with status {$status}", $status);
            }

            return $response;
        }
    }

    /**
     * Download the converted file of a result into memory. See `save()` for large files and for the rules.
     *
     * @param  int  $maxBytes  Larger files are refused.
     * @return string The file content.
     *
     * @throws InvalidArgumentException when the result has no file or `$maxBytes` is below 1; nothing was sent
     * @throws ApiException when `outputUrl` is not allowed or the download fails
     * @throws RateLimitException
     * @throws RuntimeException when the data cannot be buffered
     */
    public function download(ConvertedImage $image, int $maxBytes = self::MAX_DOWNLOAD_BYTES): string
    {
        $url = $this->downloadUrl($image, $maxBytes);
        $buffer = fopen('php://temp', 'w+b');

        if ($buffer === false) {
            throw new RuntimeException('Cannot open a temporary buffer');
        }

        try {
            $this->fetchFile($image, $url, $buffer, $maxBytes);
            rewind($buffer);

            return (string) stream_get_contents($buffer);
        } finally {
            fclose($buffer);
        }
    }

    /**
     * Stream the converted file of a result to a local file. The file appears only when it is complete.
     *
     * `outputUrl` must be an https key-value store record URL on the host of `Config::$baseUrl`; anything else is
     * refused without a request. The API key, when there is one, is sent to that host only, never to a redirect
     * target elsewhere. Without a key the download is anonymous. The size must match `outputBytes` when known.
     * `Config::$timeout` (at least 30 seconds) bounds the download, including retries.
     *
     * @param  string  $path  A local file path in an existing directory. Never build it from untrusted values.
     * @param  bool  $overwrite  False refuses to replace an existing file.
     * @param  int  $maxBytes  Larger files are refused.
     * @return int The number of bytes written.
     *
     * @throws InvalidArgumentException when the result has no file, `$maxBytes` is below 1 or the path is unusable or
     *                                  taken; nothing was sent
     * @throws ApiException when `outputUrl` is not allowed or the download fails
     * @throws RateLimitException
     * @throws RuntimeException when the file cannot be written
     */
    public function save(
        ConvertedImage $image,
        string $path,
        bool $overwrite = false,
        int $maxBytes = self::MAX_DOWNLOAD_BYTES,
    ): int {
        $url = $this->downloadUrl($image, $maxBytes);

        return AtomicFile::write(
            $path,
            $overwrite,
            fn (mixed $handle): int => $this->fetchFile($image, $url, $handle, $maxBytes),
        );
    }

    private function downloadUrl(ConvertedImage $image, int $maxBytes): string
    {
        if ($maxBytes < 1) {
            throw new InvalidArgumentException('maxBytes must be at least 1');
        }

        if ($image->outputUrl === null || $image->outputUrl === '') {
            throw new InvalidArgumentException(
                "Image {$image->index} has no converted file to download (status {$image->status->value})"
            );
        }

        return $image->outputUrl;
    }

    /**
     * @param  resource  $sink
     */
    private function fetchFile(ConvertedImage $image, string $url, mixed $sink, int $maxBytes): int
    {
        try {
            $token = $this->resolveApiKey();
        } catch (MissingApiKeyException) {
            $token = null; // record URLs can be public
        }

        return (new Downloader($this->config, $this->http, $this->retrier))
            ->download($url, $sink, $token, $maxBytes, $image->outputBytes);
    }

    private function validRunId(Run|string $run): string
    {
        $runId = $run instanceof Run ? $run->id : $run;

        if (preg_match(self::RUN_ID_PATTERN, $runId) !== 1) {
            throw new InvalidArgumentException('The run ID must be 1 to 64 letters and digits');
        }

        return $runId;
    }

    /**
     * The data of a run that belongs to the configured Actor. A `Run` that names its Actor is used as it is; an ID,
     * or a `Run` without `actId`, is looked up.
     *
     * @return array<mixed>
     */
    private function runState(Run|string $run, float $deadline): array
    {
        if ($run instanceof Run && $run->actId !== null) {
            $this->assertOwnActor($run->actId, $run->id, $deadline);

            return $run->toArray();
        }

        return $this->fetchOwnRun($this->validRunId($run), $deadline);
    }

    /**
     * @return array<mixed>
     */
    private function fetchOwnRun(string $runId, float $deadline): array
    {
        try {
            $data = $this->runData($this->request('GET', "/actor-runs/{$runId}", deadline: $deadline));
        } catch (ApiException $e) {
            if ($e->getCode() !== 404) {
                throw $e;
            }

            throw new ApiException("Run {$runId} was not found", 404, $e, $runId);
        }

        if (($data['id'] ?? null) !== $runId) {
            throw new ApiException('Invalid API response: missing or different run ID', runId: $runId);
        }

        $this->assertOwnActor($data['actId'] ?? null, $runId, $deadline);

        return $data;
    }

    /**
     * Runs of other Actors have other dataset rows, so they are rejected. `Config::$actorId` is usually a name while a
     * run only carries the Actor's internal ID, which is looked up once per client when no run start has told it.
     */
    private function assertOwnActor(mixed $actId, string $runId, float $deadline): void
    {
        if (! is_string($actId) || $actId === '') {
            throw new ApiException('Invalid API response: missing Actor ID of the run', runId: $runId);
        }

        if ($actId === $this->config->actorId) {
            return;
        }

        if ($this->actorApiId === null) {
            $actor = str_replace('/', '~', $this->config->actorId);
            $data = $this->request('GET', "/acts/{$actor}", deadline: $deadline)['data'] ?? null;
            $id = is_array($data) ? ($data['id'] ?? null) : null;

            if (! is_string($id) || $id === '') {
                throw new ApiException('Invalid API response: missing Actor ID', runId: $runId);
            }

            $this->actorApiId = $id;
        }

        if ($actId !== $this->actorApiId) {
            throw new ApiException("Run {$runId} belongs to another Actor", runId: $runId);
        }
    }

    /**
     * Maps run data to a `Run`. The error keeps the run ID whenever the data has a usable one, so a run that exists
     * is not lost (and not started a second time) over a response that cannot be read.
     *
     * @param  array<mixed>  $data
     * @param  string|null  $expectedId  The ID the data must be about, when it was asked for.
     */
    private function parseRun(array $data, ?string $expectedId = null): Run
    {
        $id = $data['id'] ?? null;

        if (! is_string($id) || preg_match(self::RUN_ID_PATTERN, $id) !== 1) {
            throw new ApiException('Invalid API response: missing or invalid run ID', runId: $expectedId);
        }

        if ($expectedId !== null && $id !== $expectedId) {
            throw new ApiException('Invalid API response: different run ID', runId: $expectedId);
        }

        try {
            return Run::fromArray($data);
        } catch (InvalidArgumentException $e) {
            throw new ApiException('Invalid API response: missing or unknown run status', 0, $e, $id);
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
    private function fetchImages(string $datasetId, float $deadline): array
    {
        $items = $this->request(
            'GET',
            '/datasets/'.rawurlencode($datasetId).'/items',
            ['query' => ['format' => 'json']],
            deadline: $deadline,
        );

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
     * Sends a request, retrying what `Support\Retrier` allows. With a deadline, `$retrySkipped` is set when
     * only the deadline prevented a retry.
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
        ?float $deadline = null,
        bool &$retrySkipped = false,
    ): array {
        $options['headers'] = [
            'Authorization' => 'Bearer '.$this->resolveApiKey(),
            'Accept' => 'application/json',
        ];
        $baseTimeout = $timeout ?? $this->config->requestTimeout;

        for ($attempt = 0; ; $attempt++) {
            $options['timeout'] = $this->retrier->requestTimeout($baseTimeout, $deadline);

            try {
                $response = $this->http->request($method, rtrim($this->config->baseUrl, '/').$path, $options);
                $decoded = json_decode($response->getBody()->getContents(), true, 512, JSON_THROW_ON_ERROR);
            } catch (BadResponseException $e) {
                if ($this->retrier->retry($e, $method, $path, $attempt, $deadline, $maxRetries, $retrySkipped)) {
                    continue;
                }

                $this->handleBadResponse($e);
            } catch (JsonException $e) {
                throw new ApiException('Invalid API response: malformed JSON', 0, $e);
            } catch (GuzzleException $e) {
                if ($this->retrier->retry($e, $method, $path, $attempt, $deadline, $maxRetries, $retrySkipped)) {
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

    private function handleBadResponse(BadResponseException $e): never
    {
        $response = $e->getResponse();

        if ($response->getStatusCode() === 429) {
            throw new RateLimitException('Rate limit exceeded', Retrier::retryAfter($e->getResponse()) ?? 60);
        }

        if ($response->getStatusCode() === 402) {
            [$type, $detail] = $this->errorDetails($e);
            $message = 'Apify usage or spending limit reached (HTTP 402)';

            throw new SpendingLimitException(
                $detail === null ? $message : "{$message}: {$detail}",
                $e,
                errorType: $type,
            );
        }

        throw new ApiException(
            "API request failed with status {$response->getStatusCode()}: ".$e->getMessage(),
            $response->getStatusCode(),
            $e,
        );
    }

    /**
     * Apify's error `type` and message from a `{"error": {"type": ..., "message": ...}}` body, each cleaned of control
     * characters and capped at 500 characters; null when missing or unparseable.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function errorDetails(BadResponseException $e): array
    {
        $body = $e->getResponse()->getBody();

        try {
            if ($body->isSeekable()) {
                $body->rewind();
            }

            $decoded = json_decode((string) $body, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return [null, null];
        }

        $error = is_array($decoded) ? ($decoded['error'] ?? null) : null;

        if (! is_array($error)) {
            return [null, null];
        }

        $clean = function (mixed $value): ?string {
            if (! is_string($value)) {
                return null;
            }

            $value = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value));

            return $value === '' ? null : $this->shorten($value);
        };

        return [$clean($error['type'] ?? null), $clean($error['message'] ?? null)];
    }
}
