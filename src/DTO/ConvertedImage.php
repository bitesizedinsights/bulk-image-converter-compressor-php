<?php

declare(strict_types=1);

namespace ImageConverter\DTO;

use ImageConverter\Client;
use ImageConverter\ConversionStatus;
use ImageConverter\Exception\ApiException;
use ImageConverter\Exception\RateLimitException;
use ImageConverter\SourceType;
use ImageConverter\Support\Value;
use InvalidArgumentException;
use JsonSerializable;
use RuntimeException;

final readonly class ConvertedImage implements JsonSerializable
{
    /**
     * The Actor's key format `<index>-<name>.<ext>`, with an image extension. No separator, no leading dot, no control
     * character and no extension a web server would run.
     */
    private const FILENAME_PATTERN =
        '/\A[0-9]{1,10}-[A-Za-z0-9_.-]{1,200}\.(?:jpg|jpeg|png|webp|avif|gif|tif|tiff|bmp)\z/i';

    public function __construct(
        public int $index,
        public string $source,
        public SourceType $sourceType,
        public ConversionStatus $status,
        public ?string $outputUrl = null,
        public ?string $outputKey = null,
        public ?string $sourceFormat = null,
        public ?string $outputFormat = null,
        public ?int $sourceBytes = null,
        public ?int $outputBytes = null,
        public ?int $bytesSaved = null,
        public ?float $savedPercent = null,
        public ?int $sourceWidth = null,
        public ?int $sourceHeight = null,
        public ?int $outputWidth = null,
        public ?int $outputHeight = null,
        public ?string $error = null,
    ) {}

    /** @param  array<mixed>  $data */
    public static function fromArray(array $data): self
    {
        $sourceType = Value::string($data, 'sourceType');
        $status = Value::string($data, 'status');

        return new self(
            index: Value::int($data, 'index'),
            source: Value::string($data, 'source'),
            sourceType: SourceType::tryFrom($sourceType)
                ?? throw new InvalidArgumentException("Unknown sourceType '{$sourceType}'"),
            status: ConversionStatus::tryFrom($status)
                ?? throw new InvalidArgumentException("Unknown status '{$status}'"),
            outputUrl: Value::nullableString($data, 'outputUrl'),
            outputKey: Value::nullableString($data, 'outputKey'),
            sourceFormat: Value::nullableString($data, 'sourceFormat'),
            outputFormat: Value::nullableString($data, 'outputFormat'),
            sourceBytes: Value::nullableInt($data, 'sourceBytes'),
            outputBytes: Value::nullableInt($data, 'outputBytes'),
            bytesSaved: Value::nullableInt($data, 'bytesSaved'),
            savedPercent: Value::nullableFloat($data, 'savedPercent'),
            sourceWidth: Value::nullableInt($data, 'sourceWidth'),
            sourceHeight: Value::nullableInt($data, 'sourceHeight'),
            outputWidth: Value::nullableInt($data, 'outputWidth'),
            outputHeight: Value::nullableInt($data, 'outputHeight'),
            error: Value::nullableString($data, 'error'),
        );
    }

    /**
     * The file name of the converted file, taken from `outputKey` (`<index>-<name>.<ext>`) and safe to append to a
     * directory. Null when there is no key or the key has any other form: it is rejected, never rewritten. Names are
     * unique within one run only.
     */
    public function getFilename(): ?string
    {
        if ($this->outputKey === null || preg_match(self::FILENAME_PATTERN, $this->outputKey) !== 1) {
            return null;
        }

        return $this->outputKey;
    }

    /** The lower-case extension of `getFilename()` without the dot, e.g. `webp` or `jpg`; null when that is null. */
    public function getExtension(): ?string
    {
        $filename = $this->getFilename();

        return $filename === null ? null : strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    }

    /**
     * Download the converted file into memory. Short for `Client::download()`.
     *
     * @param  Client|null  $client  Pass your configured client; null = a default one, which reads the API key from the
     *                               environment and downloads anonymously without it.
     *
     * @throws InvalidArgumentException
     * @throws ApiException
     * @throws RateLimitException
     * @throws RuntimeException
     */
    public function download(?Client $client = null, int $maxBytes = Client::MAX_DOWNLOAD_BYTES): string
    {
        return ($client ?? new Client)->download($this, $maxBytes);
    }

    /**
     * Save the converted file to `$path`. Short for `Client::save()`, which documents the rules.
     *
     * @return int The number of bytes written.
     *
     * @throws InvalidArgumentException
     * @throws ApiException
     * @throws RateLimitException
     * @throws RuntimeException
     */
    public function save(
        string $path,
        ?Client $client = null,
        bool $overwrite = false,
        int $maxBytes = Client::MAX_DOWNLOAD_BYTES,
    ): int {
        return ($client ?? new Client)->save($this, $path, $overwrite, $maxBytes);
    }

    /**
     * The Actor's dataset item shape (camelCase keys), so fromArray(toArray()) restores an equal object.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'index' => $this->index,
            'source' => $this->source,
            'sourceType' => $this->sourceType->value,
            'status' => $this->status->value,
            'outputUrl' => $this->outputUrl,
            'outputKey' => $this->outputKey,
            'sourceFormat' => $this->sourceFormat,
            'outputFormat' => $this->outputFormat,
            'sourceBytes' => $this->sourceBytes,
            'outputBytes' => $this->outputBytes,
            'bytesSaved' => $this->bytesSaved,
            'savedPercent' => $this->savedPercent,
            'sourceWidth' => $this->sourceWidth,
            'sourceHeight' => $this->sourceHeight,
            'outputWidth' => $this->outputWidth,
            'outputHeight' => $this->outputHeight,
            'error' => $this->error,
        ];
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    public function isSuccessful(): bool
    {
        return $this->status === ConversionStatus::Success;
    }

    public function isFailed(): bool
    {
        return $this->status === ConversionStatus::Failed;
    }

    /** True when the converted file is larger than the original. */
    public function isLarger(): bool
    {
        return $this->bytesSaved !== null && $this->bytesSaved < 0;
    }

    /** True when the pixel dimensions changed. False when they are unknown. */
    public function wasResized(): bool
    {
        if ($this->sourceWidth === null || $this->sourceHeight === null) {
            return false;
        }

        return $this->sourceWidth !== $this->outputWidth || $this->sourceHeight !== $this->outputHeight;
    }
}
