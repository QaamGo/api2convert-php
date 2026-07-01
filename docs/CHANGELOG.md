# Changelog

All notable changes to this package are documented here. This project adheres to
[Semantic Versioning](https://semver.org/).

## [10.1.0] - 2026-07-01

### Added
- Initial public release of the official, hand-written PHP SDK (`api2convert/sdk`), targeting PHP 8.2+.
- One-call `convert($input, $to, $options = [])` happy path that hides the create → upload → poll →
  download lifecycle, for local files, URLs and streams; returns a `ConversionResult` with
  `save()` / `contents()` / `url()`.
- `convertAsync()` for webhook-driven workflows.
- `options($target)` to discover the valid conversion options for a target format.
- Full Jobs API (`jobs()`) plus `conversions()`, `presets()`, `stats()` and `contracts()` resources.
- Built-in webhook signature verification (`Api2Convert::webhooks()->constructEvent(...)`).
- Typed exception hierarchy and automatic retry with exponential backoff for transient failures.
- PSR-18 / PSR-17 based transport; Guzzle by default, any PSR-18 client injectable.
