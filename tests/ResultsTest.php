<?php

declare(strict_types=1);

use ImageConverter\DTO\ConvertedImage;
use ImageConverter\Results;
use ImageConverter\SourceType;

/** @return list<ConvertedImage> */
function rows(array $spec): array
{
    $rows = [];

    foreach ($spec as $index => $source) {
        $data = loadFixture('image_success');
        $data['index'] = $index;
        $data['source'] = $source;
        $data['sourceType'] = str_starts_with($source, 'http') ? 'url' : 'upload';
        $rows[] = ConvertedImage::fromArray($data);
    }

    return $rows;
}

test('complete result has no missing indexes', function () {
    $urls = ['https://a.test/1.png', 'https://a.test/2.png'];
    $rows = rows([1 => $urls[0], 2 => $urls[1]]);

    expect(Results::isComplete($rows, 2))->toBeTrue()
        ->and(Results::missingIndexes($rows, 2))->toBe([])
        ->and(Results::unprocessed($rows, $urls))->toBe(['urls' => [], 'uploadKeys' => []]);
});

test('truncated result reports 3 of 5 and keeps keys', function () {
    $urls = ['u1.test/a.png', 'https://u2.test/a.png', 'https://u3.test/a.png', 'https://u4.test/a.png'];
    $keys = ['k1'];
    $rows = rows([1 => 'https://u1.test/a.png', 2 => 'https://u2.test/a.png', 3 => 'https://u3.test/a.png']);

    expect(Results::isComplete($rows, Results::inputCount($urls, $keys)))->toBeFalse()
        ->and(Results::missingIndexes($rows, 5))->toBe([4, 5])
        ->and(Results::unprocessed($rows, $urls, $keys))->toBe([
            'urls' => [3 => 'https://u4.test/a.png'],
            'uploadKeys' => [0 => 'k1'],
        ]);
});

test('duplicates collapse and the count is deduplicated', function () {
    $urls = ['https://a.test/1.png', " a.test/1.png\n", 'https://a.test/1.png', 'https://a.test/2.png'];

    expect(Results::inputCount($urls, ['k', 'k', ' ']))->toBe(3);

    $rows = rows([1 => 'https://a.test/1.png']);

    expect(Results::missingIndexes($rows, Results::inputCount($urls)))->toBe([2])
        ->and(Results::unprocessed($rows, $urls)['urls'])->toBe([3 => 'https://a.test/2.png']);
});

test('time_limit rows count as processed', function () {
    $data = loadFixture('image_failed');
    $data['index'] = 2;
    $data['error'] = 'time_limit: not started, not enough time left in this run';
    $rows = [...rows([1 => 'https://a.test/1.png']), ConvertedImage::fromArray($data)];

    expect(Results::isComplete($rows, 2))->toBeTrue()
        ->and($rows[1]->isFailed())->toBeTrue();
});

test('empty input is complete', function () {
    expect(Results::inputCount([], []))->toBe(0)
        ->and(Results::isComplete([], 0))->toBeTrue()
        ->and(Results::missingIndexes([], 0))->toBe([])
        ->and(Results::unprocessed([]))->toBe(['urls' => [], 'uploadKeys' => []]);
});

test('indexes are preserved when rows are not contiguous', function () {
    $rows = rows([2 => 'https://a.test/2.png', 4 => 'https://a.test/4.png']);

    expect(Results::missingIndexes($rows, 5))->toBe([1, 3, 5])
        ->and(SourceType::Url->value)->toBe('url');
});
