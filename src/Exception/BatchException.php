<?php

declare(strict_types=1);

namespace ImageConverter\Exception;

use ImageConverter\DTO\ConvertedImage;
use Throwable;

/**
 * A batch of `Client::convertMany()` failed. Every batch is a separate, billed run, so this exception keeps the
 * results of the batches that completed before. The original error is `getPrevious()`; `getCode()`, `$runId` and
 * `$statusMessage` come from the failed batch. No further batch is started and nothing is repeated.
 *
 * `$failed` holds the inputs of the failed batch. Its run may have been started, and may have converted (and billed)
 * some of them, or still be working: look at it (`Client::wait()` and `results()`, or the Apify Console) before
 * converting them again. `$pending` holds the inputs of the batches that were never started. Both keep the original
 * array keys. A null `$runId` means unknown, which does not prove that no run was started (for example after a
 * timeout of the run-start request).
 *
 * @phpstan-type Inputs array{urls: array<int|string, string>, uploadKeys: array<int|string, string>}
 */
final class BatchException extends ApiException
{
    /**
     * @param  list<ConvertedImage>  $results  All earlier results; `index` counts in the whole input.
     * @param  int  $batch  Number of the failed batch, starting at 1.
     * @param  int  $batchCount  Number of batches of the whole call.
     * @param  Inputs  $failed
     * @param  Inputs  $pending
     * @param  string|null  $runId  Run of the failed batch, when known.
     */
    public function __construct(
        Throwable $previous,
        public readonly array $results,
        public readonly int $batch,
        public readonly int $batchCount,
        public readonly array $failed,
        public readonly array $pending,
        ?string $runId = null,
    ) {
        parent::__construct(
            "Batch {$batch} of {$batchCount} failed: {$previous->getMessage()}",
            (int) $previous->getCode(),
            $previous,
            $runId,
            $previous instanceof ApiException ? $previous->statusMessage : null,
        );
    }
}
