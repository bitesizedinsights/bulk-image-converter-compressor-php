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

function sized(string $status, ?int $source, ?int $output, ?int $saved): ConvertedImage
{
    return ConvertedImage::fromArray([
        'status' => $status,
        'sourceBytes' => $source,
        'outputBytes' => $output,
        'bytesSaved' => $saved,
    ] + loadFixture('image_success'));
}

test('totals add up the successful rows only', function (): void {
    $results = [
        sized('success', 1000, 400, 600),
        sized('success', 100, 900, -800),
        sized('success', null, null, null),
        sized('failed', 5000, 5000, 5000),
    ];

    expect(Results::successCount($results))->toBe(3)
        ->and(Results::totalSourceBytes($results))->toBe(1100)
        ->and(Results::totalOutputBytes($results))->toBe(1300)
        ->and(Results::totalBytesSaved($results))->toBe(-200);
});

test('totals of no results are zero', function (): void {
    expect(Results::successCount([]))->toBe(0)
        ->and(Results::totalSourceBytes([]))->toBe(0)
        ->and(Results::totalOutputBytes([]))->toBe(0)
        ->and(Results::totalBytesSaved([]))->toBe(0);
});

test('bytes are formatted in steps of 1024 with their sign', function (int $bytes, string $expected): void {
    expect(Results::formatBytes($bytes))->toBe($expected);
})->with([
    [0, '0 B'],
    [812, '812 B'],
    [-812, '-812 B'],
    [1023, '1023 B'],
    [1024, '1.0 KB'],
    [-1024, '-1.0 KB'],
    [9592, '9.4 KB'],
    [1048524, '1023.9 KB'],
    [1048575, '1.0 MB'],
    [-1572864, '-1.5 MB'],
    [2147483648, '2.0 GB'],
    [1099511627776, '1.0 TB'],
    [PHP_INT_MIN, '-8388608.0 TB'],
]);

test('successful and failed rows are filtered in order as a list', function (): void {
    $results = [
        sized('failed', null, null, null),
        sized('success', 2, 1, 1),
        sized('failed', null, null, null),
        sized('success', 3, 1, 2),
    ];

    expect(Results::successful($results))->toBe([$results[1], $results[3]])
        ->and(Results::failed($results))->toBe([$results[0], $results[2]])
        ->and(Results::successful([]))->toBe([])
        ->and(Results::failed([]))->toBe([]);
});

test('rows are found by URL with the normalization of the Actor', function (string $url): void {
    $results = rows([1 => 'https://a.test/1.png', 2 => 'https://a.test/2.png', 3 => 'a.test/1.png']);

    expect(Results::byUrl([$results[0], $results[1]], $url))->toBe([$results[0]]);
})->with([
    'exact' => 'https://a.test/1.png',
    'no scheme' => 'a.test/1.png',
    'whitespace' => "  https://a.test/1.png \n",
    'control and invisible characters' => "https://a.test/\x001.p\u{200b}ng",
    'unicode space' => "\u{00a0}a.test/1.png",
]);

test('URLs that the Actor treats as different do not match', function (string $url): void {
    expect(Results::byUrl(rows([1 => 'https://a.test/1.png']), $url))->toBe([]);
})->with([
    'other scheme' => 'http://a.test/1.png',
    'path case' => 'https://a.test/1.PNG',
    'host case' => 'https://A.test/1.png',
    'trailing slash' => 'https://a.test/1.png/',
    'query' => 'https://a.test/1.png?x=1',
    'empty' => '',
    'blank' => '   ',
]);

test('uploaded files never match a URL and failed or repeated rows do', function (): void {
    $upload = ConvertedImage::fromArray(
        ['source' => 'https://a.test/1.png', 'sourceType' => 'upload'] + loadFixture('image_success'),
    );
    $failed = ConvertedImage::fromArray(['source' => 'https://a.test/1.png'] + loadFixture('image_failed'));

    expect(Results::byUrl([$upload, $failed, $failed], 'a.test/1.png'))->toBe([$failed, $failed]);
});
