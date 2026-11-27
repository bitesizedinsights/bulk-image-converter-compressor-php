# Changelog

All notable changes to this project are documented in this file. The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and this project adheres to [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Fixed

- The public `abort()` is now bounded by `Config::$timeout` like the other calls: a retry whose wait would pass it is skipped and the request timeout is cut to the time left plus 5 seconds.
- Retry safety: starting a run is no longer repeated after a `ConnectException` that can mean the request was already sent. Guzzle 7 reports a read timeout (cURL error 28) and an empty reply (52) that way, so a run could be started and billed twice. It is now repeated only after a 429 or when the request provably never left (cURL errors 5, 6, 7 and 35, a Guzzle 8 `ConnectTimeoutException`, or a connection failure without a cURL code that is not a timeout). On Guzzle 7 a connect timeout is therefore no longer retried on the run start.
- Retries now respect `Config::$timeout`, which covers the run start, the wait and all retries: a retry whose wait would pass it is skipped, each request's timeout is cut to the time left plus 5 seconds (reading the results of a successful run keeps at least 30 seconds), and when polling fails because the time is used up the run is aborted (best effort).
- `Retry-After` is now honoured on 503 responses too, not only on 429. One longer than `retryMaxDelay` is not waited out and the error is thrown.

### Changed

- A failed, aborted or timed-out run now reports the Actor's own reason: the message is `Actor run ended with status X: <statusMessage>` when the run has a non-empty string `statusMessage` (control characters stripped, capped at 500 characters), unchanged otherwise.
- Behavior change: the run start now sends Apify's run time limit (`timeout` query, seconds) and defaults it to `Config::$timeout`, so a run can no longer outlive the client's wait. Opt out with `Config::$runTimeout = 0` to keep the Actor's own limit.

### Added

- Retry hook: an optional fourth `Client` constructor argument `$onRetry` receives a `DTO\RetryEvent` (method, path with masked IDs, retry number, delay, HTTP status or cURL error, `message()`; no token or query) before the wait of each retry, for logging. What it throws is ignored. The retry rules now live in one internal class, `Support\Retrier`.
- `Results` helpers for images without a result row: `inputCount()`, `missingIndexes()`, `isComplete()` and `unprocessed()`. `convert()` returns one row per processed image, with fewer rows on the free plan (first 3), with duplicates, at the spending limit or after a shutdown; `index` is 1-based in the deduplicated list. A `time_limit` error row counts as processed. Docblock, README and llm.txt no longer say "one result per input image".
- `SpendingLimitException` (final, extends `ApiException`): thrown for HTTP 402 (Apify monthly usage or spending limit reached) on run start, polling, dataset and abort. Code 402, `$errorType` holds Apify's error `type` (null when the body is unreadable), message parts are control-character-free and capped at 500 characters. 402 is never retried.
- `ApiException::$statusMessage` (?string, last constructor parameter, default null): the run's raw `statusMessage` (control characters stripped, capped at 2000 characters), set for failed, aborted and timed-out runs and for the deadline-expiry error, together with `$runId`.
- `Config::$runTimeout` (?int, default null = `Config::$timeout`, 0 = Actor default) and a per-call `runTimeout` argument on `Client::convert()` (last, optional). Negative values throw `InvalidArgumentException`.
- When `Config::$timeout` expires while waiting for a run, the client now aborts the run (best effort, short timeout, no retries, errors swallowed) so it does not keep billing. The `ApiException` then says the run "was aborted" and carries the run ID in the new `ApiException::$runId`.
- `Client::abort(string $runId)` to abort a run explicitly.
- Run IDs for starting now and collecting later: `Client::start()` (arguments of `convert()`, returns a `Run` without waiting), `getRun()`, `wait()` and `results()`, plus the `Run` DTO and `RunStatus` enum. `wait()` never aborts the run and keeps polling while it is `ABORTING` or `TIMING-OUT`. `getRun()`, `wait()` and `results()` reject runs of other Actors. `convert()` behaves as before.
- `Client::abort()` also accepts a `Run`, returns the run in its new state instead of nothing (so a response without readable run data now throws an `ApiException`) and rejects a malformed run ID with `InvalidArgumentException` before any request.

## [0.2.0] - 2026-11-15

### Changed

- Guzzle 8 supported, Guzzle 7 still allowed (`guzzlehttp/guzzle` `^7.0 || ^8.0`).
- Breaking: the Apify API key is no longer a `Client` constructor argument or a `Config` property. Use `(new Client())->setApifyKey($apiToken)`. Without it the client reads `APIFY_API_TOKEN` or `APIFY_TOKEN` from the environment.

### Added

- Docs: free-plan limits (5 runs in total, first 3 images per run, same cap for Console test runs).
- `toArray()` and `JsonSerializable` on `ConvertedImage`, in the Actor's field names, so results can be stored and restored with `fromArray()`.
- Automatic retrying with exponential backoff and jitter (`Config::$maxRetries`, `$retryBaseDelay`, `$retryMaxDelay`): rate limits honour `Retry-After` (seconds or HTTP date, backoff when missing), 5xx and connection failures are retried, and starting a run is not repeated after a 5xx or timeout.
- Client-side validation of the Actor's string limits: `urls` items up to 2048 characters, `uploadKeys` items up to 256 and `uploadStoreName` up to 63, as `InvalidArgumentException` before any request.
- `Client::setApifyKey()` and `MissingApiKeyException`, thrown on the first API call when no key is available.

## [0.1.0] - 2026-11-08

### Added

- `Client::convert()` for bulk image conversion, compression and resizing from URLs and uploaded files.
- Typed DTO `ConvertedImage` with `isSuccessful()`, `isFailed()`, `isLarger()` and `wasResized()`.
- Enums `OutputFormat`, `ConversionStatus` and `SourceType`.
- `ApiException` and `RateLimitException`.
- Injectable Guzzle client for testing.
