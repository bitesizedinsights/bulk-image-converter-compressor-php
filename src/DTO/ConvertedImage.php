<?php

declare(strict_types=1);

namespace ImageConverter\DTO;

use ImageConverter\ConversionStatus;
use ImageConverter\SourceType;
use ImageConverter\Support\Value;
use InvalidArgumentException;

final readonly class ConvertedImage
{
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
