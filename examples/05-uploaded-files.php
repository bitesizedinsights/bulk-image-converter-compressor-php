<?php

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';

use ImageConverter\Client;
use ImageConverter\OutputFormat;

$apiToken = getenv('APIFY_API_TOKEN') ?: getenv('APIFY_TOKEN') ?: 'your_apify_api_token';

$client = new Client($apiToken);

// Files you uploaded to a key-value store, for example iPhone photos in HEIC format
$images = $client->convert(
    outputFormat: OutputFormat::Jpeg,
    quality: 85,
    maxWidth: 2000,
    uploadKeys: ['holiday-01.heic', 'holiday-02.heic'],
    uploadStoreName: 'my-photos',
);

foreach ($images as $image) {
    echo $image->isSuccessful()
        ? "{$image->source}: {$image->outputUrl}\n"
        : "{$image->source}: {$image->error}\n";
}
