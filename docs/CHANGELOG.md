# Changelog

All notable changes to this package are documented here. This project adheres to
[Semantic Versioning](https://semver.org/).

## [10.4.0] - 2026-08-07

Dependency floor raise and Guzzle 8 support. No API changes.

### Changed
- Raised the Guzzle floor to `^7.15.2 || ^8.0.1` and the PSR-7 floor to `^2.13 || ^3.0`. The
  previous `^7.5` / `^2.4` permitted versions carrying fourteen published advisories, including
  `GHSA-v5mv-p594-2x33` (HIGH, host-based check bypass). Composer 2.10 refuses advisory-affected
  versions at resolution time, so a current toolchain was never exposed — but the constraint no
  longer relies on that.
- **Guzzle 8 is now supported.** The SDK is PSR-18/PSR-17 abstracted, so both the 7.x and 8.x
  lines resolve cleanly; the full unit suite passes against each on PHP 8.2–8.5.

### CI
- The test matrix now runs at both `highest` and `lowest` dependency resolution, so the floors
  the package advertises are actually executed rather than merely declared.
- Added a `composer audit` gate at both ends of the declared range.

> Note: 10.3.0 and 10.3.1 shipped without changelog entries; this file jumps from 10.2.1 to 10.4.0.

## [10.2.1] - 2026-07-08

Security hardening for the HTTP transport and downloads.

- The transport no longer auto-follows 3xx redirects, so the `X-Api2convert-Api-Key`, `X-Api2convert-Token` and
  `X-Api2convert-Download-Password` headers can never ride a cross-host redirect. Password-less downloads are
  followed manually with the `X-Api2convert-*` headers stripped on cross-origin hops.
- Un-followed 3xx responses and malformed URLs now surface as a `NetworkException` instead of leaking
  or hanging; partial download files are cleaned up on error.
- Dynamic URL path segments are percent-encoded.
- An empty API key now throws a typed `ConfigurationException` instead of `\InvalidArgumentException`.

## [10.2.0] - 2026-07-02

First public release of the official, hand-written PHP SDK (`api2convert/sdk`), targeting PHP 8.2+.

### Core
- One-call `convert($input, $to, $options = [])` happy path that hides the create → upload → poll →
  download lifecycle for local files, URLs and streams; returns a `ConversionResult` with
  `save()` / `contents()` / `url()`.
- `convertAsync()` for webhook-driven workflows (sets `notify_status` when a `callback` is given).
- `options($target)` to discover the valid conversion options for a target format.
- Full Jobs API (`jobs()`) plus `conversions()`, `presets()`, `stats()` and `contracts()` resources.