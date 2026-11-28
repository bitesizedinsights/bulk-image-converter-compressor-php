<?php

declare(strict_types=1);

use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use ImageConverter\Client;
use ImageConverter\Config;
use ImageConverter\DTO\RetryEvent;
use ImageConverter\Exception\ApiException;
use ImageConverter\Exception\RateLimitException;
use ImageConverter\Support\UploadFile;

function localFile(string $name, string $content = 'IMAGE'): string
{
    $directory = sys_get_temp_dir().'/image-upload-'.bin2hex(random_bytes(6));
    mkdir($directory);
    file_put_contents("{$directory}/{$name}", $content);
    $GLOBALS['uploadFiles'][] = "{$directory}/{$name}";

    return "{$directory}/{$name}";
}

afterEach(function (): void {
    foreach ($GLOBALS['uploadFiles'] ?? [] as $file) {
        unlink($file);
        rmdir(dirname($file));
    }

    $GLOBALS['uploadFiles'] = [];
});

test('a file is checked and then put into the store', function (): void {
    $history = [];
    $client = makeClient([new Response(404), new Response(201)], $history);

    expect($client->uploadFile(localFile('holiday 01.HEIC'), 'AbC123'))->toBe('holiday-01.HEIC')
        ->and($history)->toHaveCount(2)
        ->and($history[0]['request']->getMethod())->toBe('HEAD')
        ->and($history[1]['request']->getMethod())->toBe('PUT')
        ->and((string) $history[1]['request']->getUri())
        ->toBe('https://api.apify.com/v2/key-value-stores/AbC123/records/holiday-01.HEIC')
        ->and($history[1]['request']->getHeaderLine('Content-Type'))->toBe('image/heic')
        ->and($history[1]['request']->getHeaderLine('Authorization'))->toBe('Bearer test-token')
        ->and((string) $history[1]['request']->getBody())->toBe('IMAGE')
        ->and($history[0]['options']['allow_redirects'])->toBeFalse()
        ->and($history[1]['options']['allow_redirects'])->toBeFalse();
});

test('an existing record is not replaced', function (): void {
    $history = [];
    $client = makeClient([new Response(200)], $history);

    expect(fn () => $client->uploadFile(localFile('a.png'), 'AbC123'))
        ->toThrow(ApiException::class, 'already has a record')
        ->and($history)->toHaveCount(1);
});

test('overwrite skips the check', function (): void {
    $history = [];
    $client = makeClient([new Response(201)], $history);
    $client->uploadFile(localFile('a.png'), 'jane.doe~my-photos', "x(1)!'.png", overwrite: true);

    expect($history)->toHaveCount(1)
        ->and($history[0]['request']->getUri()->getPath())
        ->toBe('/v2/key-value-stores/jane.doe~my-photos/records/x%281%29%21%27.png');
});

test('unknown extensions are sent as binary and a given image type is kept', function (): void {
    $history = [];
    $client = makeClient([new Response(201), new Response(201)], $history);
    $client->uploadFile(localFile('a.txt'), 'AbC123', overwrite: true);
    $client->uploadFile(localFile('b'), 'AbC123', overwrite: true, contentType: 'image/x-icon');

    expect($history[0]['request']->getHeaderLine('Content-Type'))->toBe('application/octet-stream')
        ->and($history[1]['request']->getHeaderLine('Content-Type'))->toBe('image/x-icon');
});

test('a long file name is cut to a 256 character key that keeps the extension', function (): void {
    $client = makeClient([new Response(201)]);
    $name = str_repeat('ä', 100).'.png';

    expect($client->uploadFile(localFile($name), 'AbC123', overwrite: true))->toBe('-.png');
    expect(strlen(UploadFile::key(str_repeat('a', 300).'.png', null)))->toBe(256)
        ->and(UploadFile::key(str_repeat('a', 300).'.png', null))->toEndWith('.png');
});

test('invalid arguments are rejected before any request', function (Closure $call, string $message): void {
    $history = [];
    $client = makeClient([], $history);

    expect(fn () => $call($client))->toThrow(InvalidArgumentException::class, $message)
        ->and($history)->toBe([]);
})->with([
    'missing file' => [fn (Client $c) => $c->uploadFile('/nope/a.png', 'AbC123'), 'not a readable file'],
    'directory' => [fn (Client $c) => $c->uploadFile(__DIR__, 'AbC123'), 'not a readable file'],
    'stream wrapper' => [fn (Client $c) => $c->uploadFile('https://x.test/a.png', 'AbC123'), 'plain local'],
    'null byte' => [fn (Client $c) => $c->uploadFile("a\0b", 'AbC123'), 'plain local'],
    'empty file' => [fn (Client $c) => $c->uploadFile(localFile('a.png', ''), 'AbC123'), 'empty'],
    'too large' => [
        fn (Client $c) => $c->uploadFile(localFile('a.png', str_repeat('a', 25 * 1024 * 1024 + 1)), 'AbC123'),
        'larger than 25 MB',
    ],
    'store with path' => [fn (Client $c) => $c->uploadFile(localFile('a.png'), 'a/../b'), 'store'],
    'dot store' => [fn (Client $c) => $c->uploadFile(localFile('a.png'), '..'), 'store'],
    'key with slash' => [fn (Client $c) => $c->uploadFile(localFile('a.png'), 'AbC', 'a/b'), 'record key'],
    'dot key' => [fn (Client $c) => $c->uploadFile(localFile('a.png'), 'AbC', '..'), 'record key'],
    'empty key' => [fn (Client $c) => $c->uploadFile(localFile('a.png'), 'AbC', ''), 'record key'],
    'long key' => [fn (Client $c) => $c->uploadFile(localFile('a.png'), 'AbC', str_repeat('a', 257)), 'record key'],
    'text type' => [
        fn (Client $c) => $c->uploadFile(localFile('a.png'), 'AbC', contentType: 'text/plain'),
        'contentType',
    ],
    'svg type' => [
        fn (Client $c) => $c->uploadFile(localFile('a.png'), 'AbC', contentType: 'image/svg+xml'),
        'contentType',
    ],
    'header injection' => [
        fn (Client $c) => $c->uploadFile(localFile('a.png'), 'AbC', contentType: "image/png\r\nX: 1"),
        'contentType',
    ],
    'store name' => [fn (Client $c) => $c->getOrCreateStore('-photos'), 'store name'],
    'long store name' => [fn (Client $c) => $c->getOrCreateStore(str_repeat('a', 64)), 'store name'],
    'uploadStore without keys' => [
        fn (Client $c) => $c->convert(['https://a.test/1.png'], uploadStore: 'AbC123'),
        'uploadKeys',
    ],
    'empty uploadStore' => [fn (Client $c) => $c->convert(uploadKeys: ['a'], uploadStore: ''), '1 to 200'],
    'convertMany without keys' => [
        fn (Client $c) => $c->convertMany(['https://a.test/1.png'], uploadStore: 'AbC123'),
        'uploadKeys',
    ],
]);

test('a redirect is an error and is not followed', function (): void {
    $history = [];
    $client = makeClient(
        [new Response(307, ['Location' => 'https://evil.example/']), new Response(201)],
        $history,
    );

    expect(fn () => $client->uploadFile(localFile('a.png'), 'AbC123', overwrite: true))
        ->toThrow(ApiException::class, 'status 307')
        ->and($history)->toHaveCount(1);
});

test('failed checks and uploads are reported', function (array $responses, string $class, int $code): void {
    $client = makeClient($responses);

    try {
        $client->uploadFile(localFile('a.png'), 'AbC123');
        $this->fail('expected an exception');
    } catch (ApiException $e) {
        expect($e)->toBeInstanceOf($class)->and($e->getCode())->toBe($code);
    }
})->with([
    'no permission to check' => [fn () => [new Response(403)], ApiException::class, 403],
    'unknown store' => [fn () => [new Response(404), new Response(404)], ApiException::class, 404],
    'rate limit' => [fn () => [new Response(429)], RateLimitException::class, 0],
]);

test('a server error repeats the upload with the same body and the retry event masks store and key', function (): void {
    $events = [];
    $history = [];
    $stack = HandlerStack::create(new MockHandler([new Response(500), new Response(201)]));
    $stack->push(Middleware::history($history));
    $client = (new Client(
        new Config(maxRetries: 1),
        new HttpClient(['handler' => $stack]),
        static function (): void {},
        static function (RetryEvent $event) use (&$events): void {
            $events[] = $event;
        },
    ))->setApifyKey('test-token');

    $client->uploadFile(localFile('secret-name.png'), 'AbC123', overwrite: true);

    expect($history)->toHaveCount(2)
        ->and((string) $history[1]['request']->getBody())->toBe('IMAGE')
        ->and($events[0]->path)->toBe('/key-value-stores/{id}/records/{key}');
});

test('a store is created or found by name', function (): void {
    $history = [];
    $client = makeClient([jsonResponse(201, ['data' => ['id' => 'St0re1']])], $history);

    expect($client->getOrCreateStore('my-photos'))->toBe('St0re1')
        ->and($history[0]['request']->getMethod())->toBe('POST')
        ->and((string) $history[0]['request']->getUri())
        ->toBe('https://api.apify.com/v2/key-value-stores?name=my-photos')
        ->and($history[0]['options']['allow_redirects'])->toBeFalse();
});

test('an unreadable store response is an error', function (Response $response): void {
    expect(fn () => makeClient([$response])->getOrCreateStore('my-photos'))
        ->toThrow(ApiException::class, 'store ID');
})->with([
    'no JSON' => fn () => new Response(200, [], 'nope'),
    'no ID' => fn () => jsonResponse(200, ['data' => []]),
    'odd ID' => fn () => jsonResponse(200, ['data' => ['id' => '../x']]),
]);

test('uploadStore is sent to the Actor', function (): void {
    $history = [];
    $client = makeClient([runResponse(), jsonResponse(200, [])], $history);
    $client->convert(uploadKeys: ['a.heic'], uploadStore: 'St0re1');

    expect(json_decode((string) $history[0]['request']->getBody(), true))
        ->toMatchArray(['uploadKeys' => ['a.heic'], 'uploadStore' => 'St0re1']);
});

test('convertMany sends uploadStore only with the batches that hold upload keys', function (): void {
    $history = [];
    $row = static fn (string $source, string $type): array => ['source' => $source, 'sourceType' => $type]
        + ['index' => 1] + loadFixture('image_success');
    $client = makeClient([
        runResponse(),
        jsonResponse(200, [$row('https://a.test/1.png', 'url')]),
        runResponse(),
        jsonResponse(200, [$row('a.heic', 'upload')]),
    ], $history);
    $client->convertMany(['https://a.test/1.png'], uploadKeys: ['a.heic'], batchSize: 1, uploadStore: 'St0re1');

    $inputs = [
        json_decode((string) $history[0]['request']->getBody(), true),
        json_decode((string) $history[2]['request']->getBody(), true),
    ];

    expect($inputs[0])->not->toHaveKey('uploadStore')
        ->and($inputs[1]['uploadStore'])->toBe('St0re1');
});
