# Changelog

All notable changes to this package are documented here. This project adheres to
[Semantic Versioning](https://semver.org/).

## [10.2.1] - 2026-07-08

Security hardening for the HTTP transport and downloads.

- The transport no longer auto-follows 3xx redirects, so the `X-Oc-Api-Key`, `X-Oc-Token` and
  `X-Oc-Download-Password` headers can never ride a cross-host redirect. Password-less downloads are
  followed manually with the `X-Oc-*` headers stripped on cross-origin hops.
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