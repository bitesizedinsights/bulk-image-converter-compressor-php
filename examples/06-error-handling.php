<?php

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';

use ImageConverter\Client;
use ImageConverter\Exception\ApiException;
use ImageConverter\Exception\RateLimitException;

$apiToken = getenv('APIFY_API_TOKEN') ?: getenv('APIFY_TOKEN') ?: 'your_apify_api_token';

$client = (new Client)->setApifyKey($apiToken);

try {
    $images = $client->convert([
        'https://www.python.org/static/img/python-logo.png',
        'https://example.com/not-an-image.html',
    ]);
} catch (InvalidArgumentException $e) {
    exit("Invalid arguments: {$e->getMessage()}\n");
} catch (RateLimitException $e) {
    // Wait the advised time before retrying
    sleep($e->retryAfter);

    exit("Rate limited, retry later\n");
} catch (ApiException $e) {
    exit("API error: {$e->getMessage()}\n");
}

// A single bad image does not throw: it comes back as a failed row
foreach ($images as $image) {
    if ($image->isFailed()) {
        echo "{$image->source}: failed, {$image->error}\n";

        continue;
    }

    echo "{$image->source}: saved {$image->bytesSaved} bytes\n";
}
