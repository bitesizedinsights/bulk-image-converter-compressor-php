<?php

declare(strict_types=1);

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use ImageConverter\Config;
use ImageConverter\ConversionStatus;
use ImageConverter\DTO\ConvertedImage;
use ImageConverter\Exception\ApiException;
use ImageConverter\Exception\RateLimitException;
use ImageConverter\SourceType;

const RECORD_URL = 'https://api.apify.com/v2/key-value-stores/AbC123/records/00001-cat.webp';

function downloadable(?string $url = RECORD_URL, ?int $bytes = null): ConvertedImage
{
    return new ConvertedImage(
        1,
        'https://example.com/cat.png',
        SourceType::Url,
        ConversionStatus::Success,
        outputUrl: $url,
        outputBytes: $bytes,
    );
}

/**
 * An empty directory of its own; `removeScratchDirectories()` deletes it after the test.
 */
function scratchDirectory(): string
{
    $directory = sys_get_temp_dir().'/image-converter-'.bin2hex(random_bytes(6));
    mkdir($directory);
    $GLOBALS['scratchDirectories'][] = $directory;

    return $directory;
}

/** @return list<string> */
function filesIn(string $directory): array
{
    return array_values(array_diff((array) scandir($directory), ['.', '..']));
}

afterEach(function (): void {
    foreach ($GLOBALS['scratchDirectories'] ?? [] as $directory) {
        foreach (filesIn($directory) as $file) {
            is_dir("{$directory}/{$file}") ? rmdir("{$directory}/{$file}") : unlink("{$directory}/{$file}");
        }

        rmdir($directory);
    }

    $GLOBALS['scratchDirectories'] = [];
});

test('download returns the content and sends the token to the API host without following redirects', function (): void {
    $history = [];
    $client = makeClient([new Response(200, [], 'IMAGE')], $history);

    expect(downloadable(bytes: 5)->download($client))->toBe('IMAGE')
        ->and($history)->toHaveCount(1)
        ->and($history[0]['request']->getHeaderLine('Authorization'))->toBe('Bearer test-token')
        ->and((string) $history[0]['request']->getUri())->toBe(RECORD_URL)
        ->and($history[0]['options']['allow_redirects'])->toBeFalse()
        ->and($history[0]['options']['stream'])->toBeTrue();
});

test('a signature in the URL is kept', function (): void {
    $history = [];
    $client = makeClient([new Response(200, [], 'x')], $history);
    $client->download(downloadable(RECORD_URL.'?signature=abc123'));

    expect($history[0]['request']->getUri()->getQuery())->toBe('signature=abc123');
});

test('without an API key the download is anonymous', function (): void {
    $token = getenv('APIFY_API_TOKEN');
    $other = getenv('APIFY_TOKEN');
    putenv('APIFY_API_TOKEN');
    putenv('APIFY_TOKEN');

    try {
        $history = [];
        $client = makeClient([new Response(200, [], 'x')], $history, apiKey: null);

        expect($client->download(downloadable()))->toBe('x')
            ->and($history[0]['request']->hasHeader('Authorization'))->toBeFalse();
    } finally {
        $token === false ?: putenv("APIFY_API_TOKEN={$token}");
        $other === false ?: putenv("APIFY_TOKEN={$other}");
    }
});

test('other URLs are refused before any request', function (string $url): void {
    $history = [];
    $client = makeClient([new Response(200, [], 'x')], $history);

    expect(fn () => $client->download(downloadable($url)))->toThrow(ApiException::class, 'Download refused')
        ->and($history)->toBe([]);
})->with([
    'foreign host' => 'https://evil.example/v2/key-value-stores/a/records/b',
    'look-alike host' => 'https://api.apify.com.evil.example/v2/key-value-stores/a/records/b',
    'user info trick' => 'https://api.apify.com@evil.example/v2/key-value-stores/a/records/b',
    'credentials' => 'https://user:pass@api.apify.com/v2/key-value-stores/a/records/b',
    'backslash trick' => 'https://evil.example\\@api.apify.com/v2/key-value-stores/a/records/b',
    'plain http' => 'http://api.apify.com/v2/key-value-stores/a/records/b',
    'other port' => 'https://api.apify.com:8443/v2/key-value-stores/a/records/b',
    'other API path' => 'https://api.apify.com/v2/users/me',
    'dot segment' => 'https://api.apify.com/v2/key-value-stores/a/records/..',
    'encoded dot segment' => 'https://api.apify.com/v2/key-value-stores/%2e%2e/records/b',
    'encoded slash' => 'https://api.apify.com/v2/key-value-stores/a/records/..%2F..%2Fusers',
    'file scheme' => 'file:///etc/passwd',
    'relative' => '/etc/passwd',
    'control character' => "https://api.apify.com/v2/key-value-stores/a/records/b\r\nX: 1",
]);

test('redirects are followed without the token', function (): void {
    $history = [];
    $client = makeClient([
        new Response(302, ['Location' => 'https://files.example.com/signed?sig=1']),
        new Response(307, ['Location' => '/next']),
        new Response(200, [], 'DATA'),
    ], $history);

    expect($client->download(downloadable()))->toBe('DATA')
        ->and((string) $history[1]['request']->getUri())->toBe('https://files.example.com/signed?sig=1')
        ->and($history[1]['request']->hasHeader('Authorization'))->toBeFalse()
        ->and((string) $history[2]['request']->getUri())->toBe('https://files.example.com/next')
        ->and($history[2]['request']->hasHeader('Authorization'))->toBeFalse();
});

test('a redirect to http or without a target is refused', function (array $headers): void {
    $history = [];
    $client = makeClient([new Response(302, $headers), new Response(200, [], 'x')], $history);

    expect(fn () => $client->download(downloadable()))->toThrow(ApiException::class, 'redirect target')
        ->and($history)->toHaveCount(1);
})->with([
    'http' => [['Location' => 'http://files.example.com/a']],
    'no Location' => [[]],
]);

test('endless redirects are stopped', function (): void {
    $redirect = new Response(302, ['Location' => 'https://files.example.com/a']);
    $client = makeClient([$redirect, $redirect, $redirect, $redirect]);

    expect(fn () => $client->download(downloadable()))->toThrow(ApiException::class, 'too many redirects');
});

test('files above the size limit are refused', function (Response $response): void {
    $client = makeClient([$response]);

    expect(fn () => $client->download(downloadable(), 4))->toThrow(ApiException::class, 'larger than the limit');
})->with([
    'announced' => fn () => new Response(200, ['Content-Length' => '5'], 'abcde'),
    'not announced' => fn () => new Response(200, [], 'abcde'),
]);

test('an incomplete or unexpected size is an error', function (Response $response, ?int $bytes): void {
    $client = makeClient([$response]);

    expect(fn () => $client->download(downloadable(bytes: $bytes)))->toThrow(ApiException::class, 'unexpected size');
})->with([
    'shorter than Content-Length' => [fn () => new Response(200, ['Content-Length' => '9'], 'abc'), null],
    'not the outputBytes of the row' => [fn () => new Response(200, [], 'abc'), 9],
]);

test('error statuses give a clear message without the URL', function (int $status, string $text): void {
    $client = makeClient([new Response($status)]);

    try {
        $client->download(downloadable(RECORD_URL.'?signature=secret'));
        $this->fail('expected an ApiException');
    } catch (ApiException $e) {
        expect($e->getCode())->toBe($status)
            ->and($e->getMessage())->toContain($text)->not->toContain('secret')
            ->and($e->getPrevious())->toBeNull();
    }
})->with([[401, 'access denied'], [403, 'access denied'], [404, 'expired or been deleted'], [500, 'status 500']]);

test('a 429 becomes a RateLimitException', function (): void {
    $client = makeClient([new Response(429, ['Retry-After' => '7'])]);

    expect(fn () => $client->download(downloadable()))->toThrow(RateLimitException::class);
});

test('server and connection errors are retried', function (): void {
    $history = [];
    $sleeps = [];
    $client = makeClient(
        [new ConnectException('boom', new Request('GET', RECORD_URL)), new Response(502), new Response(200, [], 'ok')],
        $history,
        new Config(maxRetries: 2),
        sleeps: $sleeps,
    );

    expect($client->download(downloadable()))->toBe('ok')
        ->and($history)->toHaveCount(3)
        ->and($sleeps)->toHaveCount(2)
        ->and($history[0]['options']['timeout'])->toBe(90);
});

test('a connection failure does not leak the URL', function (): void {
    $client = makeClient([new ConnectException('failed for '.RECORD_URL, new Request('GET', RECORD_URL))]);

    expect(fn () => $client->download(downloadable()))->toThrow(ApiException::class, 'could not be reached');
});

test('results without a file and bad limits are rejected without a request', function (): void {
    $history = [];
    $client = makeClient([], $history);

    expect(fn () => $client->download(downloadable(null)))->toThrow(InvalidArgumentException::class, 'no converted')
        ->and(fn () => $client->download(downloadable(), 0))->toThrow(InvalidArgumentException::class, 'maxBytes')
        ->and($history)->toBe([]);
});

test('save writes the complete file and leaves no temporary file', function (): void {
    $directory = scratchDirectory();
    $client = makeClient([new Response(200, [], 'IMAGE')]);

    expect(downloadable()->save("{$directory}/cat.webp", $client))->toBe(5)
        ->and(file_get_contents("{$directory}/cat.webp"))->toBe('IMAGE')
        ->and(filesIn($directory))->toBe(['cat.webp']);
});

test('save does not replace an existing file unless asked to', function (): void {
    $directory = scratchDirectory();
    file_put_contents("{$directory}/cat.webp", 'OLD');
    $history = [];
    $client = makeClient([new Response(200, [], 'NEW')], $history);

    expect(fn () => $client->save(downloadable(), "{$directory}/cat.webp"))
        ->toThrow(InvalidArgumentException::class, 'already exists')
        ->and($history)->toBe([])
        ->and(file_get_contents("{$directory}/cat.webp"))->toBe('OLD');

    $client->save(downloadable(), "{$directory}/cat.webp", overwrite: true);

    expect(file_get_contents("{$directory}/cat.webp"))->toBe('NEW');
});

test('a file created during the download is not replaced', function (): void {
    $directory = scratchDirectory();
    $client = makeClient([function () use ($directory): Response {
        file_put_contents("{$directory}/cat.webp", 'OTHER');

        return new Response(200, [], 'NEW');
    }]);

    expect(fn () => $client->save(downloadable(), "{$directory}/cat.webp"))->toThrow(RuntimeException::class)
        ->and(file_get_contents("{$directory}/cat.webp"))->toBe('OTHER')
        ->and(filesIn($directory))->toBe(['cat.webp']);
});

test('a symlink is neither followed nor written through', function (): void {
    $directory = scratchDirectory();
    symlink("{$directory}/elsewhere", "{$directory}/cat.webp");
    $client = makeClient([new Response(200, [], 'NEW')]);

    expect(fn () => $client->save(downloadable(), "{$directory}/cat.webp"))->toThrow(InvalidArgumentException::class);

    $client->save(downloadable(), "{$directory}/cat.webp", overwrite: true);

    expect(is_link("{$directory}/cat.webp"))->toBeFalse()
        ->and(file_exists("{$directory}/elsewhere"))->toBeFalse();
});

test('a failed download leaves nothing behind and keeps the old file', function (): void {
    $directory = scratchDirectory();
    file_put_contents("{$directory}/cat.webp", 'OLD');
    $client = makeClient([new Response(200, ['Content-Length' => '9'], 'abc')]);

    expect(fn () => $client->save(downloadable(), "{$directory}/cat.webp", overwrite: true))
        ->toThrow(ApiException::class)
        ->and(file_get_contents("{$directory}/cat.webp"))->toBe('OLD')
        ->and(filesIn($directory))->toBe(['cat.webp']);
});

test('unusable paths are rejected without a request', function (string $path): void {
    $history = [];
    $client = makeClient([], $history);

    expect(fn () => $client->save(downloadable(), str_replace('{dir}', scratchDirectory(), $path)))
        ->toThrow(InvalidArgumentException::class)
        ->and($history)->toBe([]);
})->with([
    'empty' => '',
    'null byte' => "{dir}/a\0b",
    'stream wrapper' => 'phar://{dir}/a.webp',
    'missing directory' => '{dir}/nope/a.webp',
    'a directory' => '{dir}',
    'trailing slash' => '{dir}/a/',
    'dot dot' => '{dir}/..',
]);
