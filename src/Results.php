<?php

declare(strict_types=1);

namespace ImageConverter;

use ImageConverter\DTO\ConvertedImage;
use ImageConverter\Support\Inputs;

/**
 * Helpers to find images that got no result row in the list returned by `Client::convert()`.
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
