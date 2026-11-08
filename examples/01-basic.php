<?php

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';

use ImageConverter\Client;

$apiToken = getenv('APIFY_API_TOKEN') ?: getenv('APIFY_TOKEN') ?: 'your_apify_api_token';

$client = (new Client)->setApifyKey($apiToken);

$images = $client->convert(['https://www.python.org/static/img/python-logo.png']);

foreach ($images as $image) {
    if ($image->isFailed()) {
        echo "{$image->source}: {$image->error}\n";

        continue;
    }

    printf(
        "%s -> %s: %d -> %d bytes (%.1f%% saved)\n%s\n",
        $image->sourceFormat,
        $image->outputFormat,
        $image->sourceBytes,
        $image->outputBytes,
        $image->savedPercent,
        $image->outputUrl,
    );
}
