# Bulk Image Converter and Compressor in PHP: WebP, AVIF, JPEG, PNG and Resizing

<!-- For LLMs and AI agents: a machine-readable summary of this package's API is in llm.txt (https://github.com/bitesizedinsights/bulk-image-converter-compressor-php/blob/master/llm.txt) -->

[![Packagist Version](https://img.shields.io/packagist/v/bitesizedinsights/bulk-image-converter-compressor)](https://packagist.org/packages/bitesizedinsights/bulk-image-converter-compressor)
[![Tests](https://github.com/bitesizedinsights/bulk-image-converter-compressor-php/actions/workflows/tests.yml/badge.svg)](https://github.com/bitesizedinsights/bulk-image-converter-compressor-php/actions/workflows/tests.yml)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/php-%5E8.3-777bb4.svg)](composer.json)

Bulk image converter and compressor for PHP: convert JPEG, PNG, WebP, AVIF, GIF, TIFF, BMP and HEIC images to WebP, AVIF, JPEG, PNG and more, set a quality, resize to a maximum width or height and get a before/after size report for every image. The library calls the [Bulk Image Converter, Compressor & Resizer Actor](https://apify.com/bite-sized-insights/bulk-image-converter-compressor-and-resizer) on [Apify](https://apify.com) and returns typed, immutable DTOs.

## Install the PHP Bulk Image Converter Package

```bash
composer require bitesizedinsights/bulk-image-converter-compressor
```

## Quick Start: Convert and Compress Images in PHP

```php
require __DIR__.'/vendor/autoload.php';

use ImageConverter\Client;

$apiToken = getenv('APIFY_API_TOKEN') ?: getenv('APIFY_TOKEN') ?: 'your_apify_api_token';

$client = (new Client())->setApifyKey($apiToken);

$images = $client->convert(['https://www.python.org/static/img/python-logo.png']);

foreach ($images as $image) {
    if ($image->isFailed()) {
        echo "{$image->source}: {$image->error}\n";

        continue;
    }

    printf("%d -> %d bytes (%.1f%% saved)\n%s\n", $image->sourceBytes, $image->outputBytes, $image->savedPercent, $image->outputUrl);
}
```

The API key is set with `setApifyKey()`. If you skip it, the client reads `APIFY_API_TOKEN` or `APIFY_TOKEN` from the environment and throws `MissingApiKeyException` on the first API call when neither is set.

You need an Apify account ([sign up](https://console.apify.com/sign-up), new accounts include free monthly credit) and an [API token](https://console.apify.com/account/integrations). Conversion costs from $0.0081 per image, see [Pricing](#pricing-cost-per-converted-image).

## Sample Image Conversion Output

The loop above prints one block per image, for example:

```
15770 -> 6178 bytes (60.8% saved)
https://api.apify.com/v2/key-value-stores/xxxxxxxxxxxxxxxxx/records/00001-python-logo.webp
```

Every `ConvertedImage` also carries the formats (`sourceFormat` `png`, `outputFormat` `webp`), the dimensions before and after (601x203 here), `bytesSaved` (9592) and the storage key of the converted file (`00001-python-logo.webp`). A URL that is not an image does not throw; it comes back as a row with `status` `failed` and an `error` such as "The file is not a supported or readable image."

## Pricing: Cost per Converted Image

Apify bills per successfully converted image:

- Free plan: $0.0125 per image ($12.50 per 1,000 images).
- Bronze: $0.0119, Silver: $0.0106, Gold, Platinum and Diamond: $0.0081 per image ($8.10 per 1,000).
- A flat $0.00005 per run and Apify's platform fee of $0.00001 per dataset row also apply.
- Failed images are not billed for the conversion.

Converting 100 images on the free plan costs about $1.25, or about $8.11 for 1,000 images on a Gold plan. Current prices are on the [Actor page](https://apify.com/bite-sized-insights/bulk-image-converter-compressor-and-resizer). [Run the Image Converter on Apify](https://apify.com/bite-sized-insights/bulk-image-converter-compressor-and-resizer).

Free-plan users get 5 runs in total; each free run converts the first 3 images (URLs plus upload keys). Console test runs have the same cap.

## Use Cases

- **Web performance:** turn photo libraries into WebP or AVIF and cap the width to speed up page loads and improve Core Web Vitals.
- **E-commerce and CMS pipelines:** normalize product images to one format, size and quality before import.
- **Mobile uploads:** convert HEIC and HEIF photos from iPhones to JPEG, with GPS and camera data stripped.
- **Storage and bandwidth savings:** shrink image archives and read the exact bytes saved per file.

## How to Convert Images in PHP

`Client::convert()` starts one Actor run for all images, waits for it and returns one `ConvertedImage` per processed image, URLs first, then uploaded files. Fewer rows than inputs are returned on the free plan (first 3 images), with duplicate inputs, at the spending limit or after a shutdown; use `Results::missingIndexes()` and `Results::unprocessed()` to find the images without a row, see [Images without a result](#images-without-a-result).

| Parameter | Type | Default | Description |
|---|---|---|---|
| `urls` | `list<string>` | `[]` | Public http(s) image URLs, up to 25 MB each |
| `outputFormat` | `OutputFormat` | `WebP` | Target format; `Original` only compresses and resizes |
| `quality` | `int` | `80` | 1 (smallest file) to 100 (best quality) |
| `maxWidth` | `?int` | `null` | Shrink wider images, 0 to 16000 pixels, never enlarges |
| `maxHeight` | `?int` | `null` | Shrink taller images, 0 to 16000 pixels, never enlarges |
| `keepAspectRatio` | `?bool` | `null` | Fit inside the maximum size without distortion (Actor default: on) |
| `stripMetadata` | `?bool` | `null` | Remove camera data and GPS, convert to sRGB (Actor default: on) |
| `backgroundColor` | `?string` | `null` | Hex color like `#ffffff` that fills transparency for JPEG and BMP |
| `uploadKeys` | `list<string>` | `[]` | Keys of files you uploaded to a key-value store |
| `uploadStoreName` | `?string` | `null` | Store holding the uploaded files; `null` = the run's default store |

At least one URL or upload key is required, up to 1000 images per run. URLs may be at most 2048 characters, upload keys 256 and `uploadStoreName` 63. Arguments outside these limits throw `InvalidArgumentException` before any request is made. Options left at `null` are not sent, so the Actor defaults apply.

<details>
<summary>ConvertedImage fields</summary>

| Field | Type | Description |
|---|---|---|
| `index` | `int` | 1-based position of the image in the Actor's deduplicated input list (URLs first, then upload keys) |
| `source` | `string` | The image URL or the upload key |
| `sourceType` | `SourceType` | `Url` or `Upload` |
| `status` | `ConversionStatus` | `Success` or `Failed` |
| `outputUrl` | `?string` | Public link to the converted file |
| `outputKey` | `?string` | Key of the converted file in the run's key-value store |
| `sourceFormat`, `outputFormat` | `?string` | Detected original format and new format, e.g. `png`, `webp` |
| `sourceBytes`, `outputBytes`, `bytesSaved` | `?int` | File sizes and the difference; `bytesSaved` is negative if the new file is larger |
| `savedPercent` | `?float` | Share of the original size saved, in percent |
| `sourceWidth`, `sourceHeight`, `outputWidth`, `outputHeight` | `?int` | Dimensions in pixels before and after |
| `error` | `?string` | Why the image failed, `null` on success |

Helpers: `isSuccessful()`, `isFailed()`, `isLarger()` (new file is bigger than the original) and `wasResized()`. Fields that do not apply are `null`, e.g. on failed rows.

</details>

### Images without a result

`convert()` returns one row per processed image. Fewer rows come back on the free plan (first 3 images), with duplicates, at the spending limit or after a shutdown. A row with a `time_limit` error (image skipped for lack of time) counts as processed, it is a failed row, not a missing one. Duplicate URLs and upload keys are processed once, so `index` is the 1-based position in the deduplicated list (URLs first, then upload keys; control characters and surrounding whitespace stripped, a scheme-less URL gets `https://`), not in your original array. `Results` applies the same normalization:

```php
use ImageConverter\Results;

$images = $client->convert($urls);

Results::isComplete($images, Results::inputCount($urls)); // false when rows are missing
Results::missingIndexes($images, Results::inputCount($urls)); // e.g. [4, 5]

// Convert the images that got no row in a second call (array keys of your input are preserved)
$rest = Results::unprocessed($images, $urls, $uploadKeys);
$more = $client->convert($rest['urls'], uploadKeys: $rest['uploadKeys']);
```

Pass a deduplicated count to `missingIndexes()` and `isComplete()`; `Results::inputCount()` computes it. Duplicates of a processed image count as processed in `unprocessed()`.

### Store and Restore Results

`ConvertedImage` implements `JsonSerializable` and has `toArray()`. The array uses the Actor's field names (`sourceType`, `outputUrl`, ...), so `fromArray()` restores an equal object:

```php
$json = json_encode($image);                                  // database column, cache, queue payload
$restored = ConvertedImage::fromArray(json_decode($json, true)); // same data again
```

## Convert HEIC and Uploaded Photos to JPEG in PHP

Upload files to an Apify key-value store and pass their record keys. Read formats include HEIC and HEIF from iPhones.

```php
$images = $client->convert(
    outputFormat: OutputFormat::Jpeg,
    uploadKeys: ['holiday-01.heic'],
    uploadStoreName: 'my-photos',
);
```

## Start Now, Collect Later: Run IDs

`convert()` blocks until the images are ready. For large batches, queue jobs or web requests, split it into steps: `start()` returns as soon as the run is created and the work continues on Apify, so you can store the run ID and collect the images later, even from another process.

```php
$run = $client->start($urls, quality: 70); // returns at once
$runId = $run->id;                          // store it

// later, with a new client
$run = $client->wait($runId);               // polls until the run succeeded (or throws)
$images = $client->results($run);           // the same list<ConvertedImage> as convert()
```

| Method | Description |
|---|---|
| `start(...)` | Same arguments as `convert()`, one run for up to 1000 images. Starts the run and returns a `Run` without waiting. |
| `getRun(string $runId)` | Looks the run up once, without waiting. Any status is returned: check `$run->status` (`RunStatus`) and `$run->isFinished()`. |
| `wait(Run\|string $run, ?int $timeout = null)` | Polls until the run succeeded. Throws `ApiException` when it ends as `FAILED`, `ABORTED` or `TIMED-OUT` or does not finish within `$timeout` seconds (default `Config::$timeout`, 0 = check once). **It never aborts the run**, so you can wait again. |
| `results(Run\|string $run)` | Reads the images of a succeeded run without waiting. Throws `ApiException` for any other status; rows of a failed, aborted or timed-out run are not read. |
| `abort(Run\|string $run)` | Asks Apify to stop the run and returns it in its new state. |

`Run` has `id`, `status`, `defaultDatasetId`, `startedAt`, `finishedAt`, `statusMessage` and `actId`, implements `JsonSerializable` and has `toArray()`/`fromArray()`. `RunStatus` has `Ready`, `Running`, `Succeeded`, `Failed`, `Aborting`, `Aborted`, `TimingOut` and `TimedOut`.

- **Never start twice by accident:** every run is billed. `start()` repeats its request only when it provably had no effect (see [Retries and Backoff](#retries-and-backoff)). After a server error, a timeout or an unreadable response a run may exist: check the Apify Console before you call `start()` again. When the response names the run but cannot be fully read, `$e->runId` holds its ID.
- **Run time limit:** the run started by `start()` gets the same Apify time limit as in `convert()`, by default `Config::$timeout` (600 s), however long you wait before collecting. For long runs set `runTimeout`, see [Run timeout](#run-timeout).
- **Run IDs:** an ID must be 1 to 64 letters and digits, anything else throws `InvalidArgumentException` before any request. An unknown ID gives an `ApiException` with code 404.
- **Other Actors:** `getRun()`, `wait()` and `results()` throw an `ApiException` for a run that does not belong to `Config::$actorId`. For this the client may read the Actor once (`GET /acts/{actorId}`). `abort()` does not check this: it stops whatever run of your account you name.
- **`Run` objects:** `wait()` and `results()` take a `Run` at its word (a succeeded one is not looked up again). `results()` with a `Run` that still says `RUNNING` throws even if the run has finished since: pass the `Run` returned by `wait()`, or the ID. Only pass `Run` objects you got from this library or restored from your own storage; for untrusted data pass the ID.
- **Results:** `index` and the `Results` helpers work as with `convert()`. The converted files and rows stay on Apify only as long as your account's data retention allows, so collect them in time.

## Image Format, Status and Source Type Enums

- `OutputFormat`: `WebP`, `Jpeg`, `Png`, `Avif`, `Original`, `Gif`, `Tiff`, `Bmp`
- `ConversionStatus`: `Success`, `Failed`
- `SourceType`: `Url`, `Upload`

## Configuration: Timeouts, Actor ID and Base URL

```php
use ImageConverter\Config;

$client = (new Client(new Config(timeout: 300, requestTimeout: 60)))->setApifyKey($apiToken);
```

`timeout` is the longest wait for a run in seconds (default 600), `requestTimeout` limits a single HTTP request (default 90). When `timeout` expires the client aborts the run on a best-effort basis (`POST /actor-runs/{id}/abort`, 10 seconds at most, no retries) so it does not keep running and billing (this applies to `convert()`, not to [`wait()`](#start-now-collect-later-run-ids)); the thrown `ApiException` then says the run "was aborted" and carries the run ID in `$e->runId`. If the abort itself fails, the original timeout message is thrown unchanged. Failed runs never trigger an abort, and rate limits and connection errors only when they use up `timeout` while polling (see Retries and Backoff). `$client->abort($runId)` aborts a run explicitly and returns its new state. `actorId` and `baseUrl` can be overridden, for example to use a copy of the Actor. Pass a Guzzle client as the second constructor argument to customize HTTP behavior.

### Run timeout

Besides the client's own wait (`timeout`), the run start sends Apify's run time limit as the `timeout` query parameter (seconds) of `POST /acts/{id}/runs`, so the platform stops a run when the client has given up. Resolution order: the `runTimeout` argument of `convert()` or `start()`, then `Config::$runTimeout`, then `Config::$timeout`. `0` sends nothing and keeps the Actor's own limit (600 s); a `timeout` of 0 or less sends nothing either. Negative values throw an `InvalidArgumentException`. The value is only sent when the run is started, never on polling, abort or dataset requests, and is separate from `requestTimeout`.

```php
$client = (new Client(new Config(timeout: 300, runTimeout: 900)))->setApifyKey($apiToken);
$client->convert($urls, runTimeout: 120); // this call only
```

## Retries and Backoff

Failed requests are retried automatically, by default up to 3 times with exponential backoff (1 s, 2 s, 4 s, each with 50 to 100 percent jitter, at most 30 s):

- **Rate limits (429):** retried after at least the `Retry-After` time, given as seconds or as an HTTP date. Without the header plain backoff is used. If the server asks for more than `retryMaxDelay`, a `RateLimitException` is thrown right away.
- **Server errors (5xx) and connection failures:** retried for reading results and polling. A `Retry-After` on a 503 is honoured the same way (a longer one than `retryMaxDelay` throws an `ApiException` right away).
- **Starting a run (`convert()`, `start()`) and `abort()`:** only retried after a 429, or after a connection failure that shows the request was never sent (DNS, connect or TLS error, and on Guzzle 8 a connect timeout). After a 5xx or any other timeout the run may already exist, and a repeat would be billed twice, so the error is thrown instead. Guzzle 7 reports a connect timeout like a read timeout (cURL error 28), so there neither is repeated.
- **Usage or spending limit (402):** never retried, a `SpendingLimitException` is thrown right away.
- **Other errors (4xx, including 408):** never retried.

**Overall time limit:** `Config::$timeout` also bounds the retries. It covers the run start, the wait for the run and all retries: no retry is started whose wait would pass it, and the timeout of each request is cut to the time left plus 5 seconds. Reading the results of a successful run always gets at least 30 seconds, so a call can take somewhat longer than `timeout`. If polling fails because this time is used up (a skipped retry, or a request that outlived it), the client aborts the run (best effort) and the error of that last request is thrown. This describes `convert()`. `start()`, `getRun()`, `wait()` and `results()` called on their own each get the limit (`wait()` uses its `$timeout` argument if given, `results()` gets at least 30 seconds), as does `abort()`, and none of them ever aborts a run on its own.

```php
$client = (new Client(new Config(maxRetries: 5, retryBaseDelay: 2.0, retryMaxDelay: 60.0)))->setApifyKey($apiToken);
// new Config(maxRetries: 0) turns retrying off
```

## Error Handling for PHP Image Conversion

- `InvalidArgumentException`: a malformed run ID, a negative `wait()` timeout, no images, more than 1000, a URL over 2048 characters, an upload key over 256, a store name over 63, quality outside 1 to 100, sizes outside 0 to 16000 or an invalid color.
- `MissingApiKeyException` (extends `ApiException`): no key via `setApifyKey()` and neither `APIFY_API_TOKEN` nor `APIFY_TOKEN` is set.
- `RateLimitException` (extends `ApiException`): HTTP 429; `$e->retryAfter` holds the advised seconds.
- `SpendingLimitException` (extends `ApiException`): HTTP 402, the Apify account reached its monthly usage or spending limit; the code is 402 and `$e->errorType` holds Apify's error `type` (null when the body is unreadable). Never retried. Catch it before `ApiException`: `try { $client->convert($urls); } catch (SpendingLimitException $e) { /* raise the limit or wait */ } catch (ApiException $e) { /* other failures */ }`.
- `ApiException`: failed requests, malformed responses, a failed, aborted or timed-out run, a run that does not finish within `timeout` (it is aborted, `$e->runId` holds its ID), or a dataset item that cannot be mapped (the message names its index). For a failed, aborted or timed-out run the message is `Actor run ended with status X: <reason>` when the run reports a status message (single line, at most 500 characters); `$e->statusMessage` holds the raw reason (at most 2000 characters, null when absent) and `$e->runId` the run ID, so you can look the run up or log it: `catch (ApiException $e) { error_log("{$e->runId}: {$e->statusMessage}"); }`.
- Single bad images never throw: they come back as `failed` rows.

## PHP Image Converter Examples

- [`examples/01-basic.php`](examples/01-basic.php): convert one image to WebP
- [`examples/02-resize-and-quality.php`](examples/02-resize-and-quality.php): maximum size and quality
- [`examples/03-public-domain-file.php`](examples/03-public-domain-file.php): fetch a public domain image, convert it
- [`examples/04-formats-and-transparency.php`](examples/04-formats-and-transparency.php): JPEG background color and AVIF
- [`examples/05-uploaded-files.php`](examples/05-uploaded-files.php): convert uploaded HEIC photos
- [`examples/06-error-handling.php`](examples/06-error-handling.php): rate limits and failed images
- [`examples/07-run-handles.php`](examples/07-run-handles.php): start a run now, collect the images later

Contributing and running the tests: see [CONTRIBUTING.md](CONTRIBUTING.md).

## FAQ

### How do I convert images to WebP in PHP?

Call `$client->convert(['https://example.com/photo.jpg'])`. WebP is the default output format.

### How much does bulk image conversion cost?

$0.0125 per converted image on the free plan, down to $0.0081 on Gold and higher plans. Failed images are not billed for the conversion. See [Pricing](#pricing-cost-per-converted-image).

### What input does it accept?

Public http(s) image URLs and files you uploaded to an Apify key-value store. Local files on your machine are not sent; upload them first or host them at a public URL.

### Which formats can I convert from and to?

Input: JPEG, PNG, WebP, AVIF, GIF, TIFF, BMP and HEIC/HEIF. Output: JPEG, PNG, WebP, AVIF, GIF, TIFF and BMP, or keep each image's own format with `OutputFormat::Original`. HEIC can be read but not written.

### What are the size limits?

Up to 25 MB and 50 megapixels per source image and up to 1000 images per run. Larger images come back as failed rows.

### Why is a converted file sometimes bigger?

Re-encoding an already heavily compressed image can increase its size. `isLarger()` tells you; lower the quality, choose WebP or AVIF or set a smaller `maxWidth`.

### Are animated images and transparency kept?

Animated GIF and WebP stay animated when converted to GIF or WebP. Transparency is kept for formats that support it; for JPEG and BMP it is filled with `backgroundColor`.

## Requirements

PHP 8.3 or newer, Guzzle 7 or 8 and an Apify API token.

## Powered by Apify

This package is a client for the [Bulk Image Converter, Compressor & Resizer Actor](https://apify.com/bite-sized-insights/bulk-image-converter-compressor-and-resizer) on Apify. [Sign up for Apify](https://console.apify.com/sign-up) to get an API token and free monthly credit.

## License

MIT, see [LICENSE](LICENSE).
