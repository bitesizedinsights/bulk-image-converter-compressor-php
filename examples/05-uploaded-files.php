<?php

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';

use ImageConverter\Client;
use ImageConverter\OutputFormat;

$apiToken = getenv('APIFY_API_TOKEN') ?: getenv('APIFY_TOKEN') ?: 'your_apify_api_token';

$client = (new Client)->setApifyKey($apiToken);

// Upload local files, for example iPhone photos in HEIC format, into your own key-value store
$store = $client->getOrCreateStore('my-photos');
$keys = [];

foreach (glob(__DIR__.'/photos/*.heic') ?: [] as $path) {
    $keys[] = $client->uploadFile($path, $store, overwrite: true);
}

if ($keys === []) {
    exit("Put some .heic files into examples/photos first.\n");
}

$images = $client->convert(
    outputFormat: OutputFormat::Jpeg,
    quality: 85,
    maxWidth: 2000,
    uploadKeys: $keys,
    uploadStore: $store,
);

// The uploads stay in the store until you delete them in the Apify Console or through the API.
foreach ($images as $image) {
    echo $image->isSuccessful()
        ? "{$image->source}: {$image->outputUrl}\n"
        : "{$image->source}: {$image->error}\n";
}
