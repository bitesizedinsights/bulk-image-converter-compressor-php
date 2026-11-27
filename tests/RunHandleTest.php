<?php

declare(strict_types=1);

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use ImageConverter\Client;
use ImageConverter\Config;
use ImageConverter\DTO\Run;
use ImageConverter\Exception\ApiException;
use ImageConverter\Exception\MissingApiKeyException;
use ImageConverter\Exception\RateLimitException;
use ImageConverter\Exception\SpendingLimitException;
use ImageConverter\OutputFormat;
use ImageConverter\RunStatus;

/** @param  array<string, mixed>  $extra */
function handleResponse(string $status = 'SUCCEEDED', array $extra = []): Response
{
    return jsonResponse(200, ['data' => $extra + [
        'id' => 'run1',
        'actId' => 'act1',
        'status' => $status,
        'defaultDatasetId' => 'ds1',
    ]]);
}

function actorResponse(string $id = 'act1'): Response
{
    return jsonResponse(200, ['data' => ['id' => $id, 'name' => 'bulk-image-converter-compressor-and-resizer']]);
}

function datasetResponse(): Response
{
    return jsonResponse(200, [loadFixture('image_success'), loadFixture('image_failed')]);
}

/**
 * @param  array<int, array<string, mixed>>  $history
 * @return list<string> "METHOD /path" of every request sent
 */
function sentRequests(array $history): array
{
    return array_values(array_map(
        static fn (array $h): string => $h['request']->getMethod().' '.$h['request']->getUri()->getPath(),
        $history,
    ));
}

function apiFailure(Closure $call): ApiException
{
    try {
        $call();
    } catch (ApiException $e) {
        return $e;
    }

    throw new RuntimeException('Expected an ApiException');
}

const ACTOR_PATH = '/v2/acts/bite-sized-insights~bulk-image-converter-compressor-and-resizer';

test('start sends one run start and returns the handle without polling', function () {
    $history = [];
    $client = makeClient([handleResponse('READY', ['startedAt' => '2026-11-27T10:00:00.000Z'])], $history);

    $run = $client->start(['https://example.com/a.png'], OutputFormat::Avif, 70, maxWidth: 800);

    expect($run)->toBeInstanceOf(Run::class)
        ->and($run->id)->toBe('run1')
        ->and($run->status)->toBe(RunStatus::Ready)
        ->and($run->actId)->toBe('act1')
        ->and($run->defaultDatasetId)->toBe('ds1')
        ->and($run->startedAt)->toBe('2026-11-27T10:00:00.000Z')
        ->and(sentRequests($history))->toBe(['POST '.ACTOR_PATH.'/runs'])
        ->and($history[0]['request']->getUri()->getQuery())->toBe('timeout=600')
        ->and(sentBody($history[0]['request']))->toBe([
            'outputFormat' => 'avif',
            'quality' => 70,
            'urls' => ['https://example.com/a.png'],
            'maxWidth' => 800,
        ]);
});

test('start and convert send the same run start', function () {
    $arguments = [
        'urls' => ['https://example.com/a.png'],
        'outputFormat' => OutputFormat::Jpeg,
        'quality' => 55,
        'maxWidth' => 10,
        'maxHeight' => 20,
        'keepAspectRatio' => false,
        'stripMetadata' => false,
        'backgroundColor' => '#000000',
        'uploadKeys' => ['a.heic'],
        'uploadStoreName' => 'store',
        'runTimeout' => 90,
    ];
    $history = [];
    $client = makeClient([handleResponse(), handleResponse(), jsonResponse(200, [])], $history);

    $client->start(...$arguments);
    $client->convert(...$arguments);

    expect((string) $history[0]['request']->getBody())->toBe((string) $history[1]['request']->getBody())
        ->and((string) $history[0]['request']->getUri())->toBe((string) $history[1]['request']->getUri());
});

test('start with runTimeout 0 sends no time limit', function () {
    $history = [];
    $client = makeClient([handleResponse('READY')], $history);

    $client->start(['https://example.com/a.png'], runTimeout: 0);

    expect($history[0]['request']->getUri()->getQuery())->toBe('');
});

test('start validates its arguments before any request', function (array $arguments) {
    $history = [];
    $client = makeClient([], $history);

    expect(fn () => $client->start(...$arguments))->toThrow(InvalidArgumentException::class)
        ->and($history)->toBe([]);
})->with([
    'no images' => [[]],
    'quality' => [['urls' => ['https://example.com/a.png'], 'quality' => 0]],
    'runTimeout' => [['urls' => ['https://example.com/a.png'], 'runTimeout' => -1]],
    'too many' => [['urls' => array_fill(0, 1001, 'https://example.com/a.png')]],
]);

test('start is never repeated when a run may exist', function (Response|Throwable $failure) {
    $history = [];
    $client = makeClient([$failure, handleResponse()], $history, new Config(retryBaseDelay: 0.0));

    expect(fn () => $client->start(['https://example.com/a.png']))->toThrow(ApiException::class)
        ->and($history)->toHaveCount(1);
})->with([
    'server error' => [new Response(500)],
    'bad gateway' => [new Response(502)],
    'unavailable' => [new Response(503, ['Retry-After' => '1'])],
    'request timeout' => [new Response(408)],
    'timeout after sending' => [fn () => timeoutAfterSending()],
    'malformed JSON' => [new Response(201, [], '{"data":')],
    'JSON without data' => [new Response(201, [], '{}')],
    'scalar JSON' => [new Response(201, [], 'true')],
    'run without id' => [jsonResponse(201, ['data' => ['status' => 'READY']])],
    'unknown status' => [jsonResponse(201, ['data' => ['id' => 'run1', 'status' => 'NEW']])],
]);

test('start is repeated only when it provably had no effect', function (Response|Throwable $failure) {
    $history = [];
    $client = makeClient([$failure, handleResponse('READY')], $history, new Config(retryBaseDelay: 0.0));

    expect($client->start(['https://example.com/a.png'])->id)->toBe('run1')
        ->and($history)->toHaveCount(2);
})->with([
    'rate limit' => [new Response(429)],
    'never sent' => [new ConnectException('cURL error 7: refused', new Request('POST', 'https://api.apify.com'))],
]);

test('an unreadable start response keeps the ID of the run that exists', function () {
    $client = makeClient([jsonResponse(201, ['data' => ['id' => 'run1', 'status' => 'NEW']])]);

    $e = apiFailure(fn () => $client->start(['https://example.com/a.png']));

    expect($e->getMessage())->toBe('Invalid API response: missing or unknown run status')
        ->and($e->runId)->toBe('run1');
});

test('start rejects a run ID that could not be used in a URL', function () {
    $client = makeClient([handleResponse('READY', ['id' => '../acts'])]);

    $e = apiFailure(fn () => $client->start(['https://example.com/a.png']));

    expect($e->getMessage())->toBe('Invalid API response: missing or invalid run ID')
        ->and($e->runId)->toBeNull();
});

test('start maps a spending limit and a missing key', function () {
    $history = [];
    $client = makeClient([new Response(402)], $history, new Config);

    expect(fn () => $client->start(['https://example.com/a.png']))->toThrow(SpendingLimitException::class)
        ->and($history)->toHaveCount(1);

    putenv('APIFY_API_TOKEN');
    putenv('APIFY_TOKEN');

    $history = [];
    $client = makeClient([], $history, apiKey: null);

    expect(fn () => $client->start(['https://example.com/a.png']))->toThrow(MissingApiKeyException::class)
        ->and($history)->toBe([]);
});

test('invalid run IDs are rejected before any request', function (string $runId) {
    $history = [];
    $client = makeClient([], $history);
    $run = new Run($runId, RunStatus::Succeeded, 'ds1', actId: 'act1');

    foreach ([
        fn () => $client->getRun($runId),
        fn () => $client->wait($runId),
        fn () => $client->wait($run),
        fn () => $client->results($runId),
        fn () => $client->results($run),
        fn () => $client->abort($runId),
        fn () => $client->abort($run),
    ] as $call) {
        expect($call)->toThrow(InvalidArgumentException::class, 'run ID');
    }

    expect($history)->toBe([]);
})->with([
    'empty' => [''],
    'dot segments' => ['..'],
    'traversal' => ['../acts/other'],
    'slash' => ['run1/abort'],
    'query' => ['run1?token=x'],
    'fragment' => ['run1#x'],
    'encoded' => ['run1%2Fabort'],
    'trailing newline' => ["run1\n"],
    'space' => ['run 1'],
    'actor name' => ['user~actor'],
    'too long' => [str_repeat('a', 65)],
]);

test('getRun returns a run in any status without throwing', function (RunStatus $status) {
    $history = [];
    $client = makeClient([handleResponse($status->value), actorResponse()], $history);

    expect($client->getRun('run1')->status)->toBe($status)
        ->and(sentRequests($history))->toBe(['GET /v2/actor-runs/run1', 'GET '.ACTOR_PATH])
        ->and($history[0]['request']->getUri()->getQuery())->toBe('')
        ->and($history[1]['request']->getHeaderLine('Authorization'))->toBe('Bearer test-token');
})->with(RunStatus::cases());

test('the Actor ID is looked up once per client', function () {
    $history = [];
    $client = makeClient([handleResponse(), actorResponse(), handleResponse()], $history);

    $client->getRun('run1');
    $client->getRun('run1');

    expect(sentRequests($history))
        ->toBe(['GET /v2/actor-runs/run1', 'GET '.ACTOR_PATH, 'GET /v2/actor-runs/run1']);
});

test('no lookup is needed after a start or with a raw Actor ID in the config', function () {
    $history = [];
    $client = makeClient([handleResponse('READY'), handleResponse()], $history);
    $client->start(['https://example.com/a.png']);
    $client->getRun('run1');

    expect(sentRequests($history))->toBe(['POST '.ACTOR_PATH.'/runs', 'GET /v2/actor-runs/run1']);

    $history = [];
    makeClient([handleResponse()], $history, new Config(actorId: 'act1', maxRetries: 0))->getRun('run1');

    expect($history)->toHaveCount(1);
});

test('runs of another Actor are rejected', function (Closure $call, array $responses) {
    $history = [];
    $client = makeClient($responses, $history);

    $e = apiFailure(fn () => $call($client));

    expect($e->getMessage())->toBe('Run run1 belongs to another Actor')
        ->and($e->runId)->toBe('run1')
        ->and(implode(' ', sentRequests($history)))->not->toContain('/datasets/')->not->toContain('/abort');
})->with([
    'getRun' => [fn (Client $c) => $c->getRun('run1')],
    'wait' => [fn (Client $c) => $c->wait('run1', 0)],
    'results' => [fn (Client $c) => $c->results('run1')],
])->with([
    'foreign' => [fn () => [handleResponse('SUCCEEDED', ['actId' => 'other']), actorResponse()]],
]);

test('a Run object of another Actor is rejected', function () {
    $history = [];
    $client = makeClient([actorResponse()], $history);
    $run = new Run('run1', RunStatus::Succeeded, 'ds1', actId: 'other');

    expect(fn () => $client->results($run))->toThrow(ApiException::class, 'belongs to another Actor')
        ->and(sentRequests($history))->toBe(['GET '.ACTOR_PATH]);
});

test('a Run without actId is looked up instead of trusted', function () {
    $history = [];
    $client = makeClient([handleResponse('RUNNING'), actorResponse()], $history);

    expect(fn () => $client->results(new Run('run1', RunStatus::Succeeded, 'foreign')))
        ->toThrow(ApiException::class, 'has status RUNNING')
        ->and(sentRequests($history))->toBe(['GET /v2/actor-runs/run1', 'GET '.ACTOR_PATH]);
});

test('lookups reject unusable responses', function (array $responses, string $message) {
    $client = makeClient($responses);

    expect(fn () => $client->getRun('run1'))->toThrow(ApiException::class, $message);
})->with([
    'no data' => [fn () => [jsonResponse(200, [])], 'missing run data'],
    'another run' => [fn () => [handleResponse('SUCCEEDED', ['id' => 'run2'])], 'missing or different run ID'],
    'no actId' => [fn () => [handleResponse('SUCCEEDED', ['actId' => null])], 'missing Actor ID of the run'],
    'actor without id' => [fn () => [handleResponse(), jsonResponse(200, ['data' => []])], 'missing Actor ID'],
    'unknown status' => [fn () => [handleResponse('NEW'), actorResponse()], 'unknown run status'],
]);

test('an unknown run ID gives a 404 ApiException with the ID', function () {
    $client = makeClient([jsonResponse(404, ['error' => ['type' => 'record-not-found']])]);

    $e = apiFailure(fn () => $client->getRun('doesNotExist'));

    expect($e->getMessage())->toBe('Run doesNotExist was not found')
        ->and($e->getCode())->toBe(404)
        ->and($e->runId)->toBe('doesNotExist');
});

test('lookup errors keep their usual mapping', function (int $status, string $class) {
    $client = makeClient([new Response($status)]);

    $e = apiFailure(fn () => $client->getRun('run1'));

    expect($e::class)->toBe($class);
})->with([
    'unauthorized' => [401, ApiException::class],
    'spending limit' => [402, SpendingLimitException::class],
    'rate limit' => [429, RateLimitException::class],
    'server error' => [500, ApiException::class],
]);

test('lookups are retried after a server error', function () {
    $history = [];
    $client = makeClient(
        [new Response(500), handleResponse(), new Response(502), actorResponse()],
        $history,
        new Config(retryBaseDelay: 0.0),
    );

    expect($client->getRun('run1')->id)->toBe('run1')
        ->and($history)->toHaveCount(4);
});

test('wait polls with waitForFinish until the run succeeded', function () {
    $history = [];
    $client = makeClient(
        [handleResponse('READY'), actorResponse(), handleResponse('RUNNING'), handleResponse()],
        $history,
    );

    expect($client->wait('run1')->isSuccessful())->toBeTrue()
        ->and($history)->toHaveCount(4)
        ->and($history[2]['request']->getUri()->getQuery())->toBe('waitForFinish=60')
        ->and($history[3]['request']->getUri()->getQuery())->toBe('waitForFinish=60');
});

test('wait holds a poll open no longer than its own timeout', function () {
    $history = [];
    $client = makeClient([handleResponse('RUNNING'), actorResponse(), handleResponse()], $history);

    $client->wait('run1', 20);

    expect($history[2]['request']->getUri()->getQuery())->toBe('waitForFinish=20');
});

test('wait returns a succeeded run from start without further requests', function () {
    $history = [];
    $client = makeClient([handleResponse()], $history);

    $run = $client->start(['https://example.com/a.png']);

    expect($client->wait($run))->toEqual($run)
        ->and($history)->toHaveCount(1);
});

test('wait throws for a run that ends without success', function (string $status) {
    $history = [];
    $client = makeClient(
        [handleResponse('RUNNING'), actorResponse(), handleResponse($status, ['statusMessage' => "Out of\nmemory"])],
        $history,
    );

    $e = apiFailure(fn () => $client->wait('run1'));

    expect($e->getMessage())->toBe("Actor run ended with status {$status}: Out of memory")
        ->and($e->runId)->toBe('run1')
        ->and($e->statusMessage)->toBe('Out of memory');
})->with(['FAILED', 'ABORTED', 'TIMED-OUT']);

test('wait sits out a run that is ending', function (string $ending, string $final) {
    $client = makeClient([handleResponse($ending), actorResponse(), handleResponse($final)]);

    expect(fn () => $client->wait('run1'))->toThrow(ApiException::class, "ended with status {$final}");
})->with([['ABORTING', 'ABORTED'], ['TIMING-OUT', 'TIMED-OUT']]);

test('an expired wait leaves the run alone, so it can be waited for again', function (string $status) {
    $history = [];
    $client = makeClient([handleResponse($status), actorResponse(), handleResponse()], $history);

    $e = apiFailure(fn () => $client->wait('run1', 0));

    expect($e->getMessage())->toBe('Actor run did not finish within 0 seconds')
        ->and($e->runId)->toBe('run1')
        ->and(sentRequests($history))->toBe(['GET /v2/actor-runs/run1', 'GET '.ACTOR_PATH])
        ->and($client->wait('run1')->isSuccessful())->toBeTrue()
        ->and(implode(' ', sentRequests($history)))->not->toContain('/abort');
})->with(['RUNNING', 'ABORTING']);

test('wait with timeout 0 looks an unfinished Run up once', function () {
    $history = [];
    $client = makeClient([handleResponse()], $history, new Config(actorId: 'act1', maxRetries: 0));

    $run = $client->wait(new Run('run1', RunStatus::Running, actId: 'act1'), 0);

    expect($run->isSuccessful())->toBeTrue()
        ->and(sentRequests($history))->toBe(['GET /v2/actor-runs/run1']);
});

test('wait polls a pending Run object', function () {
    $history = [];
    $client = makeClient([handleResponse()], $history, new Config(actorId: 'act1', maxRetries: 0));

    $client->wait(new Run('run1', RunStatus::Running, actId: 'act1'));

    expect($history)->toHaveCount(1)
        ->and($history[0]['request']->getUri()->getQuery())->toBe('waitForFinish=60');
});

test('wait without a timeout argument uses the configured timeout', function () {
    $client = makeClient([handleResponse('READY')], config: new Config(timeout: 0, actorId: 'act1', maxRetries: 0));

    expect(fn () => $client->wait('run1'))->toThrow(ApiException::class, 'did not finish within 0 seconds');
});

test('a poll that fails when the time is used up does not abort the run', function () {
    $history = [];
    $client = makeClient(
        [handleResponse('RUNNING'), new Response(429, ['Retry-After' => '5'])],
        $history,
        new Config(actorId: 'act1'),
    );

    expect(fn () => $client->wait('run1', 1))->toThrow(RateLimitException::class)
        ->and(sentRequests($history))->toBe(['GET /v2/actor-runs/run1', 'GET /v2/actor-runs/run1']);
});

test('wait rejects a negative timeout', function () {
    $history = [];

    expect(fn () => makeClient([], $history)->wait('run1', -1))->toThrow(InvalidArgumentException::class)
        ->and($history)->toBe([]);
});

test('start, wait and results give what convert gives', function () {
    $history = [];
    $client = makeClient(
        [handleResponse('READY'), handleResponse(), datasetResponse(), handleResponse(), datasetResponse()],
        $history,
    );

    $steps = $client->results($client->wait($client->start(['https://example.com/a.png'])));

    expect($steps)->toEqual($client->convert(['https://example.com/a.png']))
        ->and($steps)->toHaveCount(2)
        ->and(sentRequests($history)[2])->toBe('GET /v2/datasets/ds1/items')
        ->and($history[2]['request']->getUri()->getQuery())->toBe('format=json');
});

test('results can be collected later by run ID with a new client', function () {
    $history = [];
    $client = makeClient([handleResponse(), actorResponse(), datasetResponse()], $history);

    expect($client->results('run1'))->toHaveCount(2)
        ->and(sentRequests($history))
        ->toBe(['GET /v2/actor-runs/run1', 'GET '.ACTOR_PATH, 'GET /v2/datasets/ds1/items']);
});

test('results are refused unless the run succeeded', function (string $status, bool $canWait) {
    $history = [];
    $client = makeClient([handleResponse($status), actorResponse()], $history);

    $e = apiFailure(fn () => $client->results('run1'));

    expect($e->getMessage())->toBe(
        "Run run1 has status {$status}; results are only read after SUCCEEDED".($canWait ? ', call wait()' : ''),
    )
        ->and($e->runId)->toBe('run1')
        ->and($history)->toHaveCount(2);
})->with([
    ['READY', true],
    ['RUNNING', true],
    ['ABORTING', true],
    ['TIMING-OUT', true],
    ['FAILED', false],
    ['ABORTED', false],
    ['TIMED-OUT', false],
]);

test('results need a dataset ID', function (mixed $datasetId) {
    $client = makeClient([handleResponse('SUCCEEDED', ['defaultDatasetId' => $datasetId]), actorResponse()]);

    expect(fn () => $client->results('run1'))->toThrow(ApiException::class, 'missing dataset ID');
})->with([null, '', 5]);

test('results report a dataset item that cannot be mapped', function () {
    $client = makeClient([handleResponse(), actorResponse(), jsonResponse(200, [['index' => 'x']])]);

    expect(fn () => $client->results('run1'))->toThrow(ApiException::class, 'Invalid dataset item at index 0');
});

test('abort accepts a run and returns its new state', function () {
    $history = [];
    $client = makeClient([handleResponse('ABORTING')], $history);

    $run = $client->abort(new Run('run1', RunStatus::Running));

    expect($run->status)->toBe(RunStatus::Aborting)
        ->and(sentRequests($history))->toBe(['POST /v2/actor-runs/run1/abort']);
});

test('abort is not repeated after a server error', function () {
    $history = [];
    $client = makeClient([new Response(500), handleResponse('ABORTING')], $history, new Config);

    expect(fn () => $client->abort('run1'))->toThrow(ApiException::class)
        ->and($history)->toHaveCount(1);
});

test('convert still treats an ending status as unexpected', function (string $status) {
    $history = [];
    $client = makeClient([handleResponse($status)], $history);

    expect(fn () => $client->convert(['https://example.com/a.png']))
        ->toThrow(ApiException::class, 'unknown run status')
        ->and($history)->toHaveCount(1);
})->with(['ABORTING', 'TIMING-OUT']);
