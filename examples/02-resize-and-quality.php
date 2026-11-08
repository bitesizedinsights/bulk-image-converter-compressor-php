<?php

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';

use ImageConverter\Client;
use ImageConverter\OutputFormat;

$apiToken = getenv('APIFY_API_TOKEN') ?: getenv('APIFY_TOKEN') ?: 'your_apify_api_token';

$client = (new Client)->setApifyKey($apiToken);

// Shrink to at most 800x600 pixels (never enlarged) and trade some quality for size
$images = $client->convert(
    urls: ['https://upload.wikimedia.org/wikipedia/commons/4/47/PNG_transparency_demonstration_1.png'],
    outputFormat: OutputFormat::WebP,
    quality: 70,
    maxWidth: 800,
    maxHeight: 600,
);

foreach ($images as $image) {
    if ($image->isFailed()) {
        echo "{$image->source}: {$image->error}\n";

        continue;
    }

    printf(
        "%dx%d -> %dx%d, resized: %s, larger than original: %s\n",
        $image->sourceWidth,
        $image->sourceHeight,
        $image->outputWidth,
        $image->outputHeight,
        $image->wasResized() ? 'yes' : 'no',
        $image->isLarger() ? 'yes' : 'no',
    );
}
