<?php

declare(strict_types=1);

namespace ImageConverter;

use Closure;
use ImageConverter\DTO\ConvertedImage;
use ImageConverter\Support\Inputs;

/**
 * Helpers for the list returned by `Client::convert()`: find images without a result row, filter, count and add up
 * results and format byte sizes.
 *
 * `convert()` returns one row per processed image. Fewer rows come back on the free plan (first 3 images), when
 * duplicates collapse, at the spending limit or after a shutdown. `ConvertedImage::$index` is 1-based and refers to
 * the Actor's deduplicated list: URLs first, then upload keys, each normalized (control characters and surrounding
 * whitespace stripped, a scheme-less URL gets `https://`) with repeats dropped. A row whose error starts with
 * `time_limit` is a processed row with an error and counts as processed.
 */
final class Results
{
    /**
     * Number of images the Actor sees for these inputs: URLs first, then upload keys, normalized and deduplicated.
     *
     * @param  list<string>  $urls
     * @param  list<string>  $uploadKeys
     */
    public static function inputCount(array $urls, array $uploadKeys = []): int
    {
        return count(Inputs::unique($urls, true)) + count(Inputs::unique($uploadKeys, false));
    }

    /**
     * Indexes (1-based, in the Actor's deduplicated order) from 1 to `$inputCount` that have no result row.
     *
     * @param  list<ConvertedImage>  $results
     * @param  int  $inputCount  Deduplicated input count, see `inputCount()`.
     * @return list<int>
     */
    public static function missingIndexes(array $results, int $inputCount): array
    {
        $seen = [];

        foreach ($results as $image) {
            $seen[$image->index] = true;
        }

        $missing = [];

        for ($i = 1; $i <= $inputCount; $i++) {
            if (! isset($seen[$i])) {
                $missing[] = $i;
            }
        }

        return $missing;
    }

    /**
     * True when every image of the deduplicated input has a result row (an empty input is complete).
     *
     * @param  list<ConvertedImage>  $results
     */
    public static function isComplete(array $results, int $inputCount): bool
    {
        return self::missingIndexes($results, $inputCount) === [];
    }

    /**
     * Inputs without a result row, in input order with their original array keys, ready for a second `convert()` call:
     * `convert($rest['urls'], uploadKeys: $rest['uploadKeys'])`. Inputs are matched by their normalized value, so
     * duplicates of a processed image count as processed; a repeated unprocessed input is returned once.
     *
     * @param  list<ConvertedImage>  $results
     * @param  list<string>  $urls
     * @param  list<string>  $uploadKeys
     * @return array{urls: array<int, string>, uploadKeys: array<int, string>}
     */
    public static function unprocessed(array $results, array $urls = [], array $uploadKeys = []): array
    {
        $done = [SourceType::Url->value => [], SourceType::Upload->value => []];

        foreach ($results as $image) {
            $done[$image->sourceType->value][$image->source] = true;
        }

        return [
            'urls' => self::pending($urls, true, $done[SourceType::Url->value]),
            'uploadKeys' => self::pending($uploadKeys, false, $done[SourceType::Upload->value]),
        ];
    }

    /**
     * The successfully converted rows, in their order.
     *
     * @param  list<ConvertedImage>  $results
     * @return list<ConvertedImage>
     */
    public static function successful(array $results): array
    {
        return self::where($results, static fn (ConvertedImage $image): bool => $image->isSuccessful());
    }

    /**
     * The failed rows, in their order. Images without any row are not here, see `unprocessed()`.
     *
     * @param  list<ConvertedImage>  $results
     * @return list<ConvertedImage>
     */
    public static function failed(array $results): array
    {
        return self::where($results, static fn (ConvertedImage $image): bool => $image->isFailed());
    }

    /**
     * The rows of an image URL, successful or not. Both sides are normalized the way the Actor does it (control
     * characters and surrounding whitespace stripped, a URL without `http://` or `https://` gets `https://`) and then
     * compared exactly: case, trailing slashes, percent-encoding and query order matter. Rows of uploaded files never
     * match. One run has at most one row per URL; lists merged from several calls can hold more.
     *
     * @param  list<ConvertedImage>  $results
     * @return list<ConvertedImage>
     */
    public static function byUrl(array $results, string $url): array
    {
        $wanted = Inputs::normalize($url, true);

        return self::where($results, static fn (ConvertedImage $image): bool => $wanted !== ''
            && $image->sourceType === SourceType::Url
            && Inputs::normalize($image->source, true) === $wanted);
    }

    /**
     * Number of successfully converted images.
     *
     * @param  list<ConvertedImage>  $results
     */
    public static function successCount(array $results): int
    {
        return count(self::successful($results));
    }

    /**
     * Original size in bytes of all successfully converted images; unknown sizes count as 0.
     *
     * @param  list<ConvertedImage>  $results
     */
    public static function totalSourceBytes(array $results): int
    {
        return self::sum($results, static fn (ConvertedImage $image): ?int => $image->sourceBytes);
    }

    /**
     * New size in bytes of all successfully converted images; unknown sizes count as 0.
     *
     * @param  list<ConvertedImage>  $results
     */
    public static function totalOutputBytes(array $results): int
    {
        return self::sum($results, static fn (ConvertedImage $image): ?int => $image->outputBytes);
    }

    /**
     * Bytes saved over all successfully converted images; negative when the new files are larger in total.
     *
     * @param  list<ConvertedImage>  $results
     */
    public static function totalBytesSaved(array $results): int
    {
        return self::sum($results, static fn (ConvertedImage $image): ?int => $image->bytesSaved);
    }

    /**
     * A byte count for people, in steps of 1024 as in the Actor's status message: `812 B`, `9.4 KB`, `-1.5 MB`,
     * `2.0 GB`. A negative value, such as the savings of a file that grew, keeps its sign.
     */
    public static function formatBytes(int $bytes): string
    {
        if ($bytes > -1024 && $bytes < 1024) {
            return "{$bytes} B";
        }

        $value = $bytes / 1024;
        $unit = 'KB';

        foreach (['MB', 'GB', 'TB'] as $next) {
            // Judged after rounding, so 1048575 bytes read as 1.0 MB and not as 1024.0 KB.
            if (abs(round($value, 1)) < 1024) {
                break;
            }

            $value /= 1024;
            $unit = $next;
        }

        return sprintf('%.1F %s', $value, $unit);
    }

    /**
     * @param  list<ConvertedImage>  $results
     * @param  Closure(ConvertedImage): bool  $keep
     * @return list<ConvertedImage>
     */
    private static function where(array $results, Closure $keep): array
    {
        return array_values(array_filter($results, $keep));
    }

    /**
     * @param  list<ConvertedImage>  $results
     * @param  Closure(ConvertedImage): ?int  $bytes
     */
    private static function sum(array $results, Closure $bytes): int
    {
        $total = 0;

        foreach ($results as $image) {
            if ($image->isSuccessful()) {
                $total += $bytes($image) ?? 0;
            }
        }

        return $total;
    }

    /**
     * @param  array<int, string>  $inputs
     * @param  array<string, true>  $done
     * @return array<int, string>
     */
    private static function pending(array $inputs, bool $isUrl, array $done): array
    {
        $pending = [];

        foreach (Inputs::unique($inputs, $isUrl) as $key => $value) {
            if (! isset($done[$value])) {
                $pending[$key] = $inputs[$key];
            }
        }

        return $pending;
    }
}
