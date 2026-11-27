<?php

declare(strict_types=1);

namespace ImageConverter\DTO;

use ImageConverter\RunStatus;
use ImageConverter\Support\Value;
use InvalidArgumentException;
use JsonSerializable;

/** Handle of an Apify run: keep `$id` to look the run up or collect its results later. */
final readonly class Run implements JsonSerializable
{
    /**
     * @param  string|null  $actId  Apify's internal ID of the Actor the run belongs to.
     */
    public function __construct(
        public string $id,
        public RunStatus $status,
        public ?string $defaultDatasetId = null,
        public ?string $startedAt = null,
        public ?string $finishedAt = null,
        public ?string $statusMessage = null,
        public ?string $actId = null,
    ) {}

    /**
     * Only `id` and `status` are required; any other field that is missing or not a string becomes null, so odd
     * metadata never costs the caller the handle of a run that exists.
     *
     * @param  array<mixed>  $data
     *
     * @throws InvalidArgumentException when `id` or `status` is missing or the status is unknown
     */
    public static function fromArray(array $data): self
    {
        $status = Value::string($data, 'status');
        $optional = static fn (string $key): ?string => is_string($data[$key] ?? null) ? $data[$key] : null;

        return new self(
            id: Value::string($data, 'id'),
            status: RunStatus::tryFrom($status)
                ?? throw new InvalidArgumentException("Field 'status' has an unknown value"),
            defaultDatasetId: $optional('defaultDatasetId'),
            startedAt: $optional('startedAt'),
            finishedAt: $optional('finishedAt'),
            statusMessage: $optional('statusMessage'),
            actId: $optional('actId'),
        );
    }

    public function isFinished(): bool
    {
        return $this->status->isFinished();
    }

    public function isSuccessful(): bool
    {
        return $this->status === RunStatus::Succeeded;
    }

    /**
     * Apify's field names, so the result can be passed back to `fromArray()`.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'defaultDatasetId' => $this->defaultDatasetId,
            'startedAt' => $this->startedAt,
            'finishedAt' => $this->finishedAt,
            'statusMessage' => $this->statusMessage,
            'actId' => $this->actId,
        ];
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
