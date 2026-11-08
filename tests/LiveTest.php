<?php

declare(strict_types=1);

use ImageConverter\Client;
use ImageConverter\Config;
use ImageConverter\OutputFormat;

/**
 * Live test against the real Apify Actor. Costs a fraction of a cent.
 *
 * Runs only with APIFY_LIVE_TEST=1 and a token in APIFY_API_TOKEN or APIFY_TOKEN.
 */
const LIVE_SAMPLE_URL = 'https://upload.wikimedia.org/wikipedia/commons/4/47/PNG_transparency_demonstration_1.png';

function liveToken(): ?string
{
    foreach (['APIFY_API_TOKEN', 'APIFY_TOKEN'] as $name) {
        $value = getenv($name);

        if (is_string($value) && $value !== '') {
            return $value;
        }
    }

    return null;
}

function liveEnabled(): bool
{
    return getenv('APIFY_LIVE_TEST') === '1' && liveToken() !== null;
}

/**
 * Fetches the sample image once and keeps it in the system temp directory.
 */
function liveSampleFile(): string
{
    $path = sys_get_temp_dir().'/image-converter-live-'.md5(LIVE_SAMPLE_URL).'.png';

    if (! is_file($path) || filesize($path) === 0) {
        $context = stream_context_create([
            'http' => ['timeout' => 60, 'user_agent' => 'bulk-image-converter-compressor-php-tests'],
        ]);
        $data = file_get_contents(LIVE_SAMPLE_URL, false, $context);

        if ($data === false || $data === '') {
            throw new RuntimeException('Could not fetch the live test sample file');
        }

        file_put_contents($path, $data);
    }

    return $path;
}

test('live: converts a real image', function () {
    expect(filesize(liveSampleFile()))->toBeGreaterThan(0);

    $token = liveToken();
    assert($token !== null);

    $client = new Client($token, new Config(apiToken: $token, timeout: 600));

    $result = $client->convert(
        urls: [LIVE_SAMPLE_URL],
        outputFormat: OutputFormat::WebP,
        quality: 80,
        maxWidth: 400,
    );

    expect($result)->toHaveCount(1);

    $image = $result[0];

    expect($image->isSuccessful())->toBeTrue($image->error ?? '')
        ->and($image->outputUrl)->toBeString()->not->toBeEmpty()
        ->and($image->outputFormat)->toBe('webp')
        ->and($image->sourceFormat)->toBe('png')
        ->and($image->sourceBytes)->toBeGreaterThan(0)
        ->and($image->outputBytes)->toBeGreaterThan(0)
        ->and($image->outputWidth)->toBeLessThanOrEqual(400)
        ->and($image->wasResized())->toBeTrue();
})->skip(fn () => ! liveEnabled(), 'Set APIFY_LIVE_TEST=1 and APIFY_API_TOKEN (or APIFY_TOKEN) to run live tests');
