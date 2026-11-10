# Changelog

All notable changes to this project are documented in this file. The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and this project adheres to [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Changed

- Breaking: the Apify API key is no longer a `Client` constructor argument or a `Config` property. Use `(new Client())->setApifyKey($apiToken)`. Without it the client reads `APIFY_API_TOKEN` or `APIFY_TOKEN` from the environment.

### Added

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
