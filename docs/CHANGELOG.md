# Changelog

All notable changes to this package are documented here. This project adheres to
[Semantic Versioning](https://semver.org/).

## [10.2.0] - 2026-07-02

First public release of the official, hand-written PHP SDK (`api2convert/sdk`), targeting PHP 8.2+.

### Core
- One-call `convert($input, $to, $options = [])` happy path that hides the create → upload → poll →
  download lifecycle for local files, URLs and streams; returns a `ConversionResult` with
  `save()` / `contents()` / `url()`.
- `convertAsync()` for webhook-driven workflows (sets `notify_status` when a `callback` is given).
- `options($target)` to discover the valid conversion options for a target format.
- Full Jobs API (`jobs()`) plus `conversions()`, `presets()`, `stats()` and `contracts()` resources.