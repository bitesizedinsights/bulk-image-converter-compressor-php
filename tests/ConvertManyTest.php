<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\Response;
use ImageConverter\Config;
use ImageConverter\DTO\ConvertedImage;
use ImageConverter\OutputFormat;
use ImageConverter\Results;
use Psr\Http\Message\RequestInterface;

/** @return list<string> */
function manyUrls(int $count): array
{
    return array_map(static fn (int $i): string => "https://example.com/{$i}.png", $count > 0 ? range(1, $count) : []);
}

/** @return list<string> */
function manyKeys(int $count): array
{
    return array_map(static fn (int $i): string => "key-{$i}.heic", $count > 0 ? range(1, $count) : []);
}

/**
 * Dataset rows as one run returns them: `index` starts at 1 in every run, URLs come before upload keys.
 *
 * @param  list<string>  $urls
 * @param  list<string>  $keys
 * @return list<array<string, mixed>>
 */
function imageRows(array $urls, array $keys = []): array
{
    $rows = [];

    foreach ([...$urls, ...$keys] as $i => $source) {
        $rows[] = array_merge(loadFixture('image_success'), [
            'index' => $i + 1,
            'source' => $source,
            'sourceType' => $i < count($urls) ? 'url' : 'upload',
            'outputKey' => sprintf('%05d-image.webp', $i + 1),
        ]);
    }

    return $rows;
}

/**
 * Responses of successful runs, one per batch, that return a row for every input.
 *
 * @param  list<string>  $urls
 * @param  list<string>  $keys
 * @return list<Response>
 */
function batchResponses(array $urls, array $keys = [], int $size = 1000): array
{
    $responses = [];
    $items = [...array_map(fn ($u) => ['url', $u], $urls), ...array_map(fn ($k) => ['key', $k], $keys)];

    foreach (array_chunk($items, max(1, $size)) as $number => $batch) {
        $of = fn (string $kind): array => array_column(array_filter($batch, fn ($i) => $i[0] === $kind), 1);
        $responses[] = runResponse(id: "run{$number}", datasetId: "ds{$number}");
        $responses[] = jsonResponse(200, imageRows($of('url'), $of('key')));
    }

    return $responses;
}

/**
 * The JSON bodies of all run starts.
 *
 * @param  array<int, array<string, mixed>>  $history
 * @return list<array<string, mixed>>
 */
function startedRuns(array $history): array
{
    $bodies = [];

    foreach ($history as $entry) {
        $request = $entry['request'];
        assert($request instanceof RequestInterface);

        if ($request->getMethod() === 'POST' && str_ends_with($request->getUri()->getPath(), '/runs')) {
            /** @var array<string, mixed> $body */
            $body = json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR);
            $bodies[] = $body;
        }
    }

    return $bodies;
}

/**
 * @param  array<int, array<string, mixed>>  $history
 * @return list<array{int, int}> URL and upload key count of every run.
 */
function runSizes(array $history): array
{
    return array_map(
        static fn (array $body): array => [
            count((array) ($body['urls'] ?? [])),
            count((array) ($body['uploadKeys'] ?? [])),
        ],
        startedRuns($history),
    );
}

/**
 * @param  list<ConvertedImage>  $images
 * @return list<array{int, string}>
 */
function indexedSources(array $images): array
{
    return array_map(static fn (ConvertedImage $image): array => [$image->index, $image->source], $images);
}

/**
 * @param  list<string>  $sources
 * @return list<array{int, string}>
 */
function numbered(array $sources): array
{
    return array_map(static fn (int $i, string $source): array => [$i + 1, $source], array_keys($sources), $sources);
}

test('convertMany splits at the limit, counting URLs and upload keys together', function (
    int $urlCount,
    int $keyCount,
    array $sizes,
) {
    $history = [];
    $urls = manyUrls($urlCount);
    $keys = manyKeys($keyCount);
    $client = makeClient(batchResponses($urls, $keys), $history);

    $result = $client->convertMany($urls, uploadKeys: $keys);

    expect(runSizes($history))->toBe($sizes)
        ->and($history)->toHaveCount(2 * count($sizes))
        ->and(indexedSources($result))->toBe(numbered([...$urls, ...$keys]))
        ->and(Results::isComplete($result, Results::inputCount($urls, $keys)))->toBeTrue();
})->with([
    'one URL' => [1, 0, [[1, 0]]],
    'one upload key' => [0, 1, [[0, 1]]],
    'one below the limit' => [999, 0, [[999, 0]]],
    'the limit' => [1000, 0, [[1000, 0]]],
    'limit plus one' => [1001, 0, [[1000, 0], [1, 0]]],
    'exact multiple' => [2000, 0, [[1000, 0], [1000, 0]]],
    'mixed at the limit' => [600, 400, [[600, 400]]],
    'mixed over the limit' => [600, 401, [[600, 400], [0, 1]]],
    'URLs end on the border' => [1000, 5, [[1000, 0], [0, 5]]],
    'URLs cross the border' => [1500, 700, [[1000, 0], [500, 500], [0, 200]]],
    'keys only over the limit' => [0, 1001, [[0, 1000], [0, 1]]],
]);

test('index refers to the whole input, whatever the order of the rows in a run', function () {
    $urls = manyUrls(3);
    $keys = manyKeys(2);
    $client = makeClient([
        runResponse(), jsonResponse(200, array_reverse(imageRows([$urls[0], $urls[1]]))),
        runResponse(), jsonResponse(200, array_reverse(imageRows([$urls[2]], [$keys[0]]))),
        runResponse(), jsonResponse(200, imageRows([], [$keys[1]])),
    ]);

    expect(indexedSources($client->convertMany($urls, uploadKeys: $keys, batchSize: 2)))->toBe([
        [2, $urls[1]], [1, $urls[0]], [4, $keys[0]], [3, $urls[2]], [5, $keys[1]],
    ]);
});

test('remapping changes nothing but the index', function () {
    $urls = manyUrls(2);
    $client = makeClient(batchResponses($urls, size: 1));

    $result = $client->convertMany($urls, batchSize: 1);

    expect($result[1]->toArray())->toBe(['index' => 2] + ConvertedImage::fromArray(imageRows([$urls[1]])[0])->toArray())
        ->and($result[1]->outputKey)->toBe('00001-image.webp')
        ->and($result[0]->outputKey)->toBe($result[1]->outputKey);
});

test('failed rows are results and do not stop the batches', function () {
    $history = [];
    $urls = manyUrls(2);
    $failed = ['status' => 'failed', 'error' => 'time_limit: not started, not enough time left in this run'];
    $client = makeClient([
        runResponse(), jsonResponse(200, [array_merge(imageRows([$urls[0]])[0], $failed)]),
        runResponse(), jsonResponse(200, imageRows([$urls[1]])),
    ], $history);

    $result = $client->convertMany($urls, batchSize: 1);

    expect($result)->toHaveCount(2)
        ->and($result[0]->isFailed())->toBeTrue()
        ->and($result[1]->index)->toBe(2)
        ->and(runSizes($history))->toBe([[1, 0], [1, 0]]);
});

test('convertMany with nothing to convert makes no request and needs no API key', function (array $urls, array $keys) {
    $history = [];
    $client = makeClient([], $history, apiKey: null);

    expect($client->convertMany($urls, uploadKeys: $keys))->toBe([])
        ->and($history)->toBe([]);
})->with([
    'nothing' => [[], []],
    'only blank entries' => [['', '  ', "\u{00A0}\u{3000}"], ["\n", "\u{200B}"]],
]);

test('convertMany honours a custom batch size', function (int $count, int $size, array $sizes) {
    $history = [];
    $urls = manyUrls($count);
    $client = makeClient(batchResponses($urls, size: $size), $history);

    expect(indexedSources($client->convertMany($urls, batchSize: $size)))->toBe(numbered($urls))
        ->and(array_column(runSizes($history), 0))->toBe($sizes);
})->with([
    'size one' => [3, 1, [1, 1, 1]],
    'exact multiple' => [6, 3, [3, 3]],
    'remainder' => [7, 3, [3, 3, 1]],
]);

test('convertMany rejects an invalid batch size before any request', function (int $size) {
    $history = [];
    $client = makeClient([], $history);

    expect(fn () => $client->convertMany(manyUrls(2), batchSize: $size))
        ->toThrow(InvalidArgumentException::class, 'batchSize must be 1 to 1000')
        ->and(fn () => $client->convertMany(batchSize: $size))->toThrow(InvalidArgumentException::class)
        ->and($history)->toBe([]);
})->with([0, -1, 1001]);

test('convertMany validates everything before the first run', function (array $options, string $message) {
    $history = [];
    $client = makeClient([], $history);

    expect(fn () => $client->convertMany(...$options + ['urls' => manyUrls(3), 'batchSize' => 1]))
        ->toThrow(InvalidArgumentException::class, $message)
        ->and($history)->toBe([]);
})->with([
    'too long URL in the last batch' => [
        ['urls' => [...manyUrls(3), 'https://example.com/'.str_repeat('a', 2048)]], 'urls[3]',
    ],
    'non-string URL' => [['urls' => [...manyUrls(3), 5]], 'urls[3] must be a string'],
    'too long upload key' => [['uploadKeys' => ['ok', str_repeat('k', 257)]], 'uploadKeys[1]'],
    'non-string upload key' => [['uploadKeys' => [null]], 'uploadKeys[0] must be a string'],
    'store name' => [['uploadStoreName' => str_repeat('s', 64)], 'uploadStoreName'],
    'quality' => [['quality' => 0], 'Quality'],
    'width' => [['maxWidth' => 16001], 'maxWidth'],
    'height' => [['maxHeight' => -1], 'maxHeight'],
    'color' => [['backgroundColor' => 'red'], 'backgroundColor'],
    'run timeout' => [['runTimeout' => -1], 'runTimeout'],
    'options with empty input' => [['urls' => [], 'quality' => 101], 'Quality'],
]);

test('convertMany sends every option to every batch', function () {
    $history = [];
    $urls = manyUrls(3);
    $keys = manyKeys(2);
    $client = makeClient(batchResponses($urls, $keys, 2), $history);

    $client->convertMany(
        $urls,
        outputFormat: OutputFormat::Jpeg,
        quality: 65,
        maxWidth: 1600,
        maxHeight: 900,
        keepAspectRatio: false,
        stripMetadata: false,
        backgroundColor: '#FFEEDD',
        uploadKeys: $keys,
        uploadStoreName: 'my-photos',
        runTimeout: 321,
        batchSize: 2,
    );

    $options = [
        'uploadStoreName' => 'my-photos',
        'maxWidth' => 1600,
        'maxHeight' => 900,
        'keepAspectRatio' => false,
        'stripMetadata' => false,
        'backgroundColor' => '#FFEEDD',
    ];
    $head = ['outputFormat' => 'jpeg', 'quality' => 65];

    expect(startedRuns($history))->toBe([
        $head + ['urls' => [$urls[0], $urls[1]]] + $options,
        $head + ['urls' => [$urls[2]], 'uploadKeys' => [$keys[0]]] + $options,
        $head + ['uploadKeys' => [$keys[1]]] + $options,
    ]);

    foreach ([0, 2, 4] as $start) {
        expect($history[$start]['request']->getUri()->getQuery())->toBe('timeout=321');
    }
});

test('unset options are left out of every batch', function () {
    $history = [];
    $urls = manyUrls(2);
    $client = makeClient(batchResponses($urls, size: 1), $history, new Config(timeout: 900, maxRetries: 0));

    $client->convertMany($urls, batchSize: 1);

    foreach ($urls as $number => $url) {
        expect(startedRuns($history)[$number])->toBe(['outputFormat' => 'webp', 'quality' => 80, 'urls' => [$url]])
            ->and($history[2 * $number]['request']->getUri()->getQuery())->toBe('timeout=900')
            ->and($history[2 * $number + 1]['request']->getUri()->getPath())->toBe("/v2/datasets/ds{$number}/items");
    }
});

test('duplicates are converted once, also across batch borders', function () {
    $history = [];
    $a = 'https://example.com/a.png';
    $b = 'https://example.com/b.png';
    $c = 'https://example.com/c.png';
    $urls = [$a, $b, 'example.com/a.png', '', " {$b}\n", "\u{00A0}{$a}\u{3000}", "\u{FEFF}{$b}", $c, $a];
    // An upload key equal to a URL is another image; a key without a scheme gets none.
    $keys = [$a, 'photo.heic', " photo.heic\t", $a, 'example.com/a.png'];
    $client = makeClient(batchResponses([$a, $b, $c], [$a, 'photo.heic', 'example.com/a.png'], 2), $history);

    $result = $client->convertMany($urls, uploadKeys: $keys, batchSize: 2);

    expect(runSizes($history))->toBe([[2, 0], [1, 1], [0, 2]])
        ->and(indexedSources($result))->toBe(numbered([$a, $b, $c, $a, 'photo.heic', 'example.com/a.png']))
        ->and(Results::inputCount($urls, $keys))->toBe(6)
        ->and(Results::isComplete($result, 6))->toBeTrue()
        ->and(Results::unprocessed($result, $urls, $keys))->toBe(['urls' => [], 'uploadKeys' => []]);
});

test('first occurrences are sent as written, as JSON lists even for other array keys', function () {
    $history = [];
    $client = makeClient([runResponse(), jsonResponse(200, [])], $history);

    $client->convertMany(['x' => 'example.com/a.png', 7 => 'https://example.com/a.png'], uploadKeys: [3 => ' k ']);

    expect(startedRuns($history)[0]['urls'])->toBe(['example.com/a.png'])
        ->and(startedRuns($history)[0]['uploadKeys'])->toBe([' k '])
        ->and((string) $history[0]['request']->getBody())->toContain('"urls":["', '"uploadKeys":["');
});
