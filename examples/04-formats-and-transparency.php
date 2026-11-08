<?php

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';

use ImageConverter\Client;
use ImageConverter\OutputFormat;

$apiToken = getenv('APIFY_API_TOKEN') ?: getenv('APIFY_TOKEN') ?: 'your_apify_api_token';

$client = (new Client)->setApifyKey($apiToken);

$url = 'https://upload.wikimedia.org/wikipedia/commons/4/47/PNG_transparency_demonstration_1.png';

// JPEG has no transparency: fill transparent areas with a light grey instead of the default white
$jpeg = $client->convert([$url], outputFormat: OutputFormat::Jpeg, quality: 85, backgroundColor: '#f0f0f0');

// AVIF gives the smallest files; keep metadata such as camera data by turning stripping off
$avif = $client->convert([$url], outputFormat: OutputFormat::Avif, quality: 60, stripMetadata: false);

foreach ([...$jpeg, ...$avif] as $image) {
    if ($image->isFailed()) {
        echo "{$image->source}: {$image->error}\n";

        continue;
    }

    printf("%s: %d bytes (%.1f%% saved)\n", $image->outputFormat, $image->outputBytes, $image->savedPercent);
}
