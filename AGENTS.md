# AGENTS — maintaining the API2Convert PHP SDK

This SDK is **hand-written** (not generated from OpenAPI) and kept in sync with the API by a human
**or an AI agent**. This file is the playbook for that. The model: a committed spec snapshot is the
diff baseline, a fixed behavior contract protects the ergonomics, and the test suite is the guardrail.

## Why hand-written

The conversion flow is multi-step (create → upload → poll → download) and the **upload step is not
in the OpenAPI spec at all**, so a generator cannot produce a usable client. We optimise for a
junior-friendly surface — one-call `convert()` — and use AI to keep it current.

## Repo layout

| Path | What it is |
|------|------------|
| `src/Api2Convert.php` | The client + the `convert()` / `convertAsync()` façade. **Hand-authored.** |
| `src/ConversionResult.php`, `src/FileDownload.php` | Result + download helpers. **Hand-authored.** |
| `src/Upload/FileUploader.php` | Multipart upload to the per-job server. **Hand-authored** (not in the spec). |
| `src/Resource/*` | One class per API tag (Jobs, Conversions, Presets, Stats, Contracts). **Derived** from the spec. |
| `src/Model/*`, `src/Enum/*` | Typed DTOs/enums. **Derived** from the spec. |
| `src/Http/*` | Transport: auth, retries/backoff, error mapping. Mostly stable infrastructure. |
| `src/Exception/*` | The typed exception hierarchy. |
| `openapi/api2convert.openapi.json` | **Committed spec snapshot** the SDK targets — the diff baseline. |
| `docs/SDK_CONTRACT.md` | The fixed, language-agnostic public surface + semantics. |
| `tests/Unit/*` | Offline golden tests (PSR-18 mock client). **The guardrail.** |
| `tests/Live/*` | End-to-end conformance against the real API (skipped without a key). |

## How to update the SDK to a new API version

1. **Refresh the snapshot.** Fetch the latest spec and overwrite `openapi/api2convert.openapi.json`:
   ```bash
   curl -s https://api.api2convert.com/v2/openapi.json -o openapi/api2convert.openapi.json   # or /v2/schema (Swagger 2.0)
   git diff --stat openapi/
   ```
2. **Diff it.** Inspect the change: new/removed/renamed operations, new fields, new enum values.
3. **Update the DERIVED layer to match the diff, and nothing else:**
   - New/changed fields → update the relevant `Model/*` DTO (`fromArray` + a typed property).
   - New operation → add a method on the matching `Resource/*` class (mirror the existing style).
   - New input/output target types → extend the matching `Enum/*`.
4. **Do NOT change the hand-authored public API** (`convert`, `convertAsync`, `download`, upload,
   polling, webhook verification, exception classes) unless `docs/SDK_CONTRACT.md` changes first.
   If a real product change requires it, update `docs/SDK_CONTRACT.md` in the same change and bump
   the **major** version.
5. **Lint + test (the guardrail):**
   ```bash
   composer check        # phpcs (PSR-12) + phpstan (level 8) + phpunit — all must pass
   ```
   Add or update a golden test in `tests/Unit/` for any new behavior. Keep `tests/Live/` runnable.
6. **Record + version.** Add an entry to `docs/CHANGELOG.md` and bump the version in
   `src/Api2Convert.php::VERSION` and `composer.json` per SemVer (additive spec change → minor;
   breaking public-surface change → major).

## Guarantees to uphold (don't break these)

- **Never commit a real API key, token or secret** — not in source, tests, fixtures, examples,
  CI files or commit messages, and never publish one anywhere. Keys come only from environment
  variables (`API2CONVERT_API_KEY`) or masked/protected CI variables; tests use obvious fakes
  (`test-key`, `whsec_test`, …). The SDK must never log or expose a key/token in errors. Run a
  secret scan before any release.

- **The contract is law.** Public method names, signatures and semantics match `docs/SDK_CONTRACT.md`
  across every SDK language. Adapt only to PHP idiom.
- **Upload uses the per-job `X-Api2convert-Token`, never the account key.** There is a test for this.
- **`convert()` stays one call** for the common case (path/URL/stream → `to` → `save()`).
- **Transient failures retry; failures surface as typed exceptions.** Never leak a raw HTTP/transport
  error to the caller.
- **PHP 8.2+, `declare(strict_types=1)`, readonly DTOs, PSR-12, PHPStan level 8.**
- **Minimal dependencies.** PSR-18/17 + Guzzle as the default client. Don't add heavy deps.

## Conventions

- Models parse defensively via `Support\Data` (tolerate missing/extra fields — never throw on a
  surprising payload during hydration).
- Resource methods are thin: build the request, call `Transport`, hydrate a model.
- Keep the README quickstart copy-pasteable; if you change the happy path, update the README example.
