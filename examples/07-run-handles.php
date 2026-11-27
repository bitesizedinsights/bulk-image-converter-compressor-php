<?php

declare(strict_types=1);

// Start a run now and collect its images later, for example from a queue job.

require __DIR__.'/../vendor/autoload.php';

use ImageConverter\Client;
use ImageConverter\Exception\ApiException;

$client = new Client; // reads APIFY_API_TOKEN or APIFY_TOKEN

$runId = $client->start(['https://www.python.org/static/img/python-logo.png'])->id;
echo "Started run {$runId}\n"; // store it; do not start again if a later step fails

try {
    $run = $client->wait($runId, timeout: 300);

    foreach ($client->results($run) as $image) {
        echo $image->isSuccessful() ? $image->outputUrl : $image->error, "\n";
    }
} catch (ApiException $e) {
    // After an expired wait the run keeps going: wait again later or call $client->abort($runId).
    echo "Run {$runId}: {$e->getMessage()}\n";
}
