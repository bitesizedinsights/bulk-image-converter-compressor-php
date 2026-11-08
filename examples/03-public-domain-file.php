<?php

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';

use ImageConverter\Client;
use ImageConverter\OutputFormat;

$apiToken = getenv('APIFY_API_TOKEN') ?: getenv('APIFY_TOKEN') ?: 'your_apify_api_token';

// Public domain: PNG transparency demonstration (Wikimedia Commons)
$sourceUrl = 'https://upload.wikimedia.org/wikipedia/commons/4/47/PNG_transparency_demonstration_1.png';

// Fetch the file once and keep it in the temp directory
$localFile = sys_get_temp_dir().'/image-converter-transparency-demo.png';

if (! is_file($localFile) || filesize($localFile) === 0) {
    $context = stream_context_create(['http' => ['timeout' => 60, 'user_agent' => 'image-converter-php-example']]);
    $data = file_get_contents($sourceUrl, false, $context);

    if ($data === false) {
        exit("Could not download {$sourceUrl}\n");
    }

    file_put_contents($localFile, $data);
}

printf("Local copy: %s (%d KB)\n", $localFile, filesize($localFile) / 1024);

// The Actor reads public URLs, so send the source URL rather than the local file
$client = (new Client)->setApifyKey($apiToken);
[$image] = $client->convert([$sourceUrl], outputFormat: OutputFormat::WebP, quality: 80);

if ($image->isFailed()) {
    exit("Failed: {$image->error}\n");
}

printf(
    "%s %dx%d, %d -> %d bytes (%.1f%% saved)\n%s\n",
    strtoupper((string) $image->outputFormat),
    $image->outputWidth,
    $image->outputHeight,
    $image->sourceBytes,
    $image->outputBytes,
    $image->savedPercent,
    $image->outputUrl,
);
