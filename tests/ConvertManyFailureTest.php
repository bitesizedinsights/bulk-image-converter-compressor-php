<?php

declare(strict_types=1);

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use ImageConverter\Config;
use ImageConverter\Exception\ApiException;
use ImageConverter\Exception\BatchException;
use ImageConverter\Exception\MissingApiKeyException;
use ImageConverter\Exception\RateLimitException;
use ImageConverter\Exception\SpendingLimitException;
use ImageConverter\Results;

// Four URLs and two upload keys in batches of two: batch 1, 2 and 3 are the first, a middle and the last one. The URLs
// fill batch 1 and 2, the keys batch 3. Mixed batches are covered separately below.

function catchBatch(Closure $call): BatchException
{
    try {
        $call();
    } catch (BatchException $e) {
        return $e;
    }

    throw new RuntimeException('Expected a BatchException');
}

/**
 * The failure modes of one batch: its responses, the class and message of the cause, and the run ID when known.
 *
 * @return array<string, array{Closure, class-string<Throwable>, string, string|null}>
 */
function failureModes(): array
{
    $unsent = new Request('POST', 'https://api.apify.com');

    return [
        'run start 500' => [fn () => [new Response(500)], ApiException::class, 'status 500', null],
        'run start read timeout' => [
            fn () => [new ConnectException('cURL error 28: Operation timed out', $unsent)],
            ApiException::class, 'cURL error 28', null,
        ],
        'run start 402' => [fn () => [new Response(402)], SpendingLimitException::class, 'limit reached', null],
        'failed run' => [fn () => [runResponse('FAILED', 'bad')], ApiException::class, 'status FAILED', 'bad'],
        'aborted run' => [fn () => [runResponse('ABORTED', 'bad')], ApiException::class, 'status ABORTED', 'bad'],
        'timed-out run' => [
            fn () => [runResponse('TIMED-OUT', 'bad')], ApiException::class, 'status TIMED-OUT', 'bad',
        ],
        'polling 404' => [
            fn () => [runResponse('RUNNING', 'bad'), new Response(404)], ApiException::class, 'status 404', 'bad',
        ],
        'polling 429' => [
            fn () => [runResponse('RUNNING', 'bad'), ...array_fill(0, 4, new Response(429))],
            RateLimitException::class, 'Rate limit', 'bad',
        ],
        'results 500' => [
            fn () => [runResponse(id: 'bad'), ...array_fill(0, 4, new Response(500))],
            ApiException::class, 'status 500', 'bad',
        ],
        'results 402' => [
            fn () => [runResponse(id: 'bad'), new Response(402)], SpendingLimitException::class, 'limit', 'bad',
        ],
        'invalid dataset item' => [
            fn () => [runResponse(id: 'bad'), jsonResponse(200, [['index' => 'x']])],
            ApiException::class, 'Invalid dataset item', 'bad',
        ],
        'unexpected error on run start' => [
            fn () => [new RuntimeException('boom')], RuntimeException::class, 'boom', null,
        ],
        'PHP error while reading results' => [
            fn () => [runResponse(id: 'bad'), new Error('broken')], Error::class, 'broken', 'bad',
        ],
        'malformed JSON' => [
            fn () => [runResponse(id: 'bad'), new Response(200, [], '{')],
            ApiException::class, 'malformed JSON', 'bad',
        ],
    ];
}

test('a failing batch keeps the earlier results and nothing is repeated or started after it', function (
    int $failing,
    string $mode,
) {
    [$responses, $cause, $message, $runId] = failureModes()[$mode];
    $urls = manyUrls(4);
    $keys = manyKeys(2);
    $before = array_slice(batchResponses($urls, $keys, 2), 0, 2 * ($failing - 1));
    $history = [];
    // Default retries are on. The spare success responses show that nothing else is requested.
    $client = makeClient(
        [...$before, ...$responses(), runResponse(), jsonResponse(200, [])],
        $history,
        new Config,
    );

    $e = catchBatch(fn () => $client->convertMany($urls, uploadKeys: $keys, batchSize: 2));

    $batches = [
        ['urls' => [0 => $urls[0], 1 => $urls[1]], 'uploadKeys' => []],
        ['urls' => [2 => $urls[2], 3 => $urls[3]], 'uploadKeys' => []],
        ['urls' => [], 'uploadKeys' => $keys],
    ];
    $pending = ['urls' => [], 'uploadKeys' => []];

    foreach (array_slice($batches, $failing) as $later) {
        $pending['urls'] += $later['urls'];
        $pending['uploadKeys'] += $later['uploadKeys'];
    }

    expect(indexedSources($e->results))->toBe(array_slice(numbered([...$urls, ...$keys]), 0, 2 * ($failing - 1)))
        ->and($e->batch)->toBe($failing)
        ->and($e->batchCount)->toBe(3)
        ->and($e->failed)->toBe($batches[$failing - 1])
        ->and($e->pending)->toBe($pending)
        ->and($e->runId)->toBe($runId)
        ->and($e->getPrevious())->toBeInstanceOf($cause)
        ->and($e->getMessage())->toStartWith("Batch {$failing} of 3 failed: ")->toContain($message)
        ->and($e->getCode())->toBe($e->getPrevious()?->getCode())
        ->and(startedRuns($history))->toHaveCount($failing)
        ->and($history)->toHaveCount(count($before) + count($responses()));
})->with([1, 2, 3])->with(array_keys(failureModes()));

test('a row with an index its batch cannot have fails the batch', function (int $index) {
    $urls = manyUrls(3);
    $row = ['index' => $index] + imageRows([$urls[2]])[0];
    $client = makeClient([
        ...array_slice(batchResponses($urls, size: 2), 0, 2),
        runResponse(id: 'bad'), jsonResponse(200, [$row]),
    ]);

    $e = catchBatch(fn () => $client->convertMany($urls, batchSize: 2));

    expect($e->getMessage())->toContain("index {$index} is outside 1 to 1")
        ->and($e->results)->toHaveCount(2)
        ->and($e->runId)->toBe('bad');
})->with([0, 2, 3, -1]);

test('a batch that does not finish in time is aborted and keeps the earlier results', function (int $failing) {
    $urls = manyUrls(6);
    $history = [];
    $client = makeClient(
        [
            ...array_slice(batchResponses($urls, size: 2), 0, 2 * ($failing - 1)),
            jsonResponse(201, ['data' => ['id' => 'slow', 'status' => 'RUNNING', 'statusMessage' => 'Processed 1/2']]),
            runResponse('ABORTING', 'slow'),
            runResponse(),
        ],
        $history,
        new Config(timeout: 0),
    );

    $e = catchBatch(fn () => $client->convertMany($urls, batchSize: 2));

    expect($e->results)->toHaveCount(2 * ($failing - 1))
        ->and($e->getMessage())->toContain('did not finish within 0 seconds and was aborted')
        ->and($e->runId)->toBe('slow')
        ->and($e->statusMessage)->toBe('Processed 1/2')
        ->and($e->pending['urls'])->toHaveCount(2 * (3 - $failing))
        ->and(startedRuns($history))->toHaveCount($failing)
        ->and($history[count($history) - 1]['request']->getUri()->getPath())->toEndWith('/actor-runs/slow/abort');
})->with([1, 2, 3]);

test('every batch gets the full timeout, not what earlier batches left over', function () {
    $urls = manyUrls(2);
    $history = [];
    $slow = static function (): Response {
        usleep(1_100_000);

        return jsonResponse(200, imageRows(['https://example.com/1.png']));
    };
    $client = makeClient(
        [runResponse(), $slow, runResponse('RUNNING'), runResponse(), jsonResponse(200, imageRows([$urls[1]]))],
        $history,
        new Config(timeout: 1, maxRetries: 0),
    );

    expect(indexedSources($client->convertMany($urls, batchSize: 1)))->toBe(numbered($urls))
        ->and($history)->toHaveCount(5);
});

test('a rate-limited run start is retried within its batch, since no run was started', function () {
    $urls = manyUrls(2);
    $history = [];
    $client = makeClient(
        [
            runResponse(), jsonResponse(200, imageRows([$urls[0]])),
            new Response(429), runResponse(), jsonResponse(200, imageRows([$urls[1]])),
        ],
        $history,
        new Config,
    );

    expect(indexedSources($client->convertMany($urls, batchSize: 1)))->toBe(numbered($urls))
        ->and(array_column(startedRuns($history), 'urls'))->toBe([[$urls[0]], [$urls[1]], [$urls[1]]]);
});

test('an upload key that cannot be encoded fails its batch and keeps the earlier results', function () {
    $urls = manyUrls(1);
    $history = [];
    $client = makeClient([runResponse(), jsonResponse(200, imageRows($urls))], $history);

    $e = catchBatch(fn () => $client->convertMany($urls, uploadKeys: ["\xB1.heic"], batchSize: 1));

    expect(indexedSources($e->results))->toBe(numbered($urls))
        ->and($e->failed)->toBe(['urls' => [], 'uploadKeys' => ["\xB1.heic"]])
        ->and($history)->toHaveCount(2);
});

test('a mixed batch reports its URLs and keys, with the original keys and the strings as written', function () {
    $client = makeClient([runResponse(), jsonResponse(200, [['index' => 'x']])]);

    $e = catchBatch(fn () => $client->convertMany(
        [10 => 'example.com/a', 20 => 'https://example.com/a', 30 => ' b.test '],
        uploadKeys: [5 => ' k1 ', 6 => 'k1', 7 => 'k2'],
        batchSize: 3,
    ));

    expect($e->failed)->toBe(['urls' => [10 => 'example.com/a', 30 => ' b.test '], 'uploadKeys' => [5 => ' k1 ']])
        ->and($e->pending)->toBe(['urls' => [], 'uploadKeys' => [7 => 'k2']])
        ->and($e)->toBeInstanceOf(ApiException::class);
});

test('what failed and what is pending can be converted again without paying twice for the rest', function () {
    $urls = manyUrls(3);
    $keys = manyKeys(3);
    $client = makeClient([
        ...array_slice(batchResponses($urls, $keys, 2), 0, 2),
        runResponse('FAILED'),
        ...batchResponses([$urls[2]], $keys, 2),
    ]);

    $e = catchBatch(fn () => $client->convertMany($urls, uploadKeys: $keys, batchSize: 2));
    $rest = $client->convertMany(
        [...$e->failed['urls'], ...$e->pending['urls']],
        uploadKeys: [...$e->failed['uploadKeys'], ...$e->pending['uploadKeys']],
        batchSize: 2,
    );

    $sources = array_map(fn ($image) => $image->source, [...$e->results, ...$rest]);

    // The second call counts its own input, so its indexes start at 1 again.
    expect($sources)->toBe([...$urls, ...$keys])
        ->and($rest[0]->index)->toBe(1)
        ->and(Results::unprocessed([...$e->results, ...$rest], $urls, $keys))
        ->toBe(['urls' => [], 'uploadKeys' => []]);
});

test('a missing API key is not wrapped, as nothing was started', function () {
    putenv('APIFY_API_TOKEN');
    putenv('APIFY_TOKEN');
    $history = [];
    $client = makeClient([], $history, apiKey: null);

    expect(fn () => $client->convertMany(manyUrls(3), batchSize: 1))->toThrow(MissingApiKeyException::class)
        ->and($history)->toBe([]);
});

test('a batch with missing rows ends the call without starting more runs', function (int $short, int $rows) {
    $urls = manyUrls(6);
    $history = [];
    $client = makeClient(
        [
            ...array_slice(batchResponses($urls, size: 2), 0, 2 * ($short - 1)),
            runResponse(),
            jsonResponse(200, imageRows(array_slice($urls, 2 * ($short - 1), $rows))),
            runResponse(),
            jsonResponse(200, []),
        ],
        $history,
    );

    $result = $client->convertMany($urls, batchSize: 2);
    $done = 2 * ($short - 1) + $rows;

    expect(indexedSources($result))->toBe(array_slice(numbered($urls), 0, $done))
        ->and(startedRuns($history))->toHaveCount($short)
        ->and(Results::missingIndexes($result, 6))->toBe($done < 6 ? range($done + 1, 6) : [])
        ->and(Results::unprocessed($result, $urls)['urls'])->toBe(array_slice($urls, $done, null, true));
})->with([1, 2, 3])->with(['no rows' => 0, 'one of two rows' => 1]);

test('the free plan cap of 3 images uses only one of the free runs', function () {
    $urls = manyUrls(2500);
    $history = [];
    $client = makeClient([runResponse(), jsonResponse(200, imageRows(array_slice($urls, 0, 3)))], $history);

    expect($client->convertMany($urls))->toHaveCount(3)
        ->and(startedRuns($history))->toHaveCount(1);
});

test('convert keeps its own limits and throws no BatchException', function () {
    $history = [];
    $client = makeClient([new Response(500)], $history);

    expect(fn () => $client->convert(manyUrls(600), uploadKeys: manyKeys(401)))
        ->toThrow(InvalidArgumentException::class, 'At most 1000 images per run');

    try {
        $client->convert(manyUrls(1));
    } catch (ApiException $e) {
        expect($e)->not->toBeInstanceOf(BatchException::class);
    }

    expect($history)->toHaveCount(1);
});
