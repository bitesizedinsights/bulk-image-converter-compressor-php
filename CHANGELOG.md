# Changelog

All notable changes to this project are documented in this file. The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and this project adheres to [Semantic Versioning](https://semver.org/).

## [0.1.0] - 2026-11-08

### Added

- `Client::convert()` for bulk image conversion, compression and resizing from URLs and uploaded files.
- Typed DTO `ConvertedImage` with `isSuccessful()`, `isFailed()`, `isLarger()` and `wasResized()`.
- Enums `OutputFormat`, `ConversionStatus` and `SourceType`.
- `ApiException` and `RateLimitException`.
- Injectable Guzzle client for testing.
