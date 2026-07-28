# newtalaria/logging

PHP SDK for [Talaria](https://newtalaria.com) — logging and error tracking. Framework-agnostic core with a Silverstripe Monolog adapter and automatic browser JS error capture.

PHP events are **queued in memory** and sent with `POST /events/ingestBatch` when the buffer hits a size limit, exceeds a max age, or the request shuts down. Fingerprinting stays on the server.

## Install

```bash
composer require newtalaria/logging
```

## Plain PHP

```php
use Talaria\Talaria;

Talaria::init([
    'dsn' => 'https://api.newtalaria.com',
    'apiKey' => 'tal_live_…',
    'environment' => 'production', // production | staging | development
    'release' => '1.2.3',
    'maxBatchSize' => 50,
    'flushIntervalMs' => 2000,
]);

Talaria::captureMessage('Checkout opened', 'info');

try {
    // …
} catch (Throwable $e) {
    Talaria::captureException($e, [
        'tags' => ['module' => 'checkout'],
        'extra' => ['order_id' => '123'],
    ]);
}

Talaria::flush(); // optional; also runs on shutdown when defaultIntegrations is true
```

### Init options

| Option | Default | Notes |
| --- | --- | --- |
| `dsn` / `baseUrl` | required | Serverpod API base URL |
| `apiKey` | required | Full `tal_live_…` key |
| `environment` | required | `production`, `staging`, or `development` |
| `release` | `null` | App / deploy version |
| `sampleRate` | `1.0` | Client-side sampling before enqueue |
| `maxBatchSize` | `50` | Flush when buffer reaches N events |
| `flushIntervalMs` | `2000` | Flush when oldest buffered event is this old (checked on enqueue) |
| `defaultIntegrations` | `true` | Uncaught exception / fatal handlers + shutdown flush |

## Silverstripe

Supported: **Silverstripe 4.13+ / 5 / 6** on **PHP 8.1+**.

1. `composer require newtalaria/logging`
2. Set environment variables (recommended):

```
TALARIA_DSN="https://api.newtalaria.com"
TALARIA_API_KEY="tal_live_…"
TALARIA_ENVIRONMENT="production"
TALARIA_RELEASE="1.2.3"
```

3. Flush config:

```bash
vendor/bin/sake dev/build flush=1
```

### PHP (Monolog)

The module pushes a Talaria Monolog handler onto the default logger (`talariaLogHandler` Injector service). Existing `Injector::inst()->get(LoggerInterface::class)->error(...)` calls are forwarded into the Talaria batch queue.

### Compatibility: Silverstripe 4 / 5 / 6 and `psr/log`

Silverstripe majors ship different logging stacks:

| CMS | Monolog | Typical `psr/log` |
| --- | --- | --- |
| 4.x | 1.x (array records) | 1.x |
| 5.x / 6.x | 3.x (`LogRecord`) | 2.x or 3.x |

**Problem:** Pinning this package to `psr/log: ^3` and `monolog: ^3` only made Composer refuse install on sites that already resolved `psr/log` 1 or 2 (common on Silverstripe 4, and valid on Silverstripe 5 when Monolog pulled in v2). Separately, Monolog 1 and Monolog 3 define incompatible `AbstractProcessingHandler::write()` signatures (`array` vs `LogRecord`), so a single handler subclass cannot run on both.

**What we do:**

1. **Composer ranges** allow both stacks:
   - `monolog/monolog: ^1.25 || ^3.0`
   - `psr/log: ^1.0 || ^2.0 || ^3.0`
2. **Two handler classes**, never a generic `TalariaLogHandler`:
   - `Talaria\SilverStripe\Handlers\TalariaLogHandlerMonolog3` — Monolog 3 (`LogRecord`)
   - `Talaria\SilverStripe\Handlers\TalariaLogHandlerMonologLegacy` — Monolog 1/2 (array records)
3. **Handlers are not Composer-autoloaded.** They live in `silverstripe/handlers/` (outside the `Talaria\SilverStripe\` PSR-4 tree). `LogHandlerFactory` `require_once`s **only** the matching file after detecting Monolog via `class_exists(\Monolog\LogRecord::class)`.
4. That matters because PHP validates `write()` against the parent as soon as the class file is loaded — **before** the factory’s `if` can help. If Injector, a stale config key, or anything else autoloads the Monolog 3 class on a Monolog 1 site, you get:
   `Declaration of …::write(LogRecord) must be compatible with …::write(array)`.
5. Injector uses a **neutral** service id (`talariaLogHandler`) + factory only — never a service named after a concrete handler class (SS may reflect/autoload the service name as a class).
6. `silverstripe/handlers/_manifest_exclude` keeps Silverstripe’s class manifest from discovering those files.
7. Shared capture / Member / Director enrichment lives in `LogHandlerSupport`.
8. **`Talaria\Logger` keeps an untyped PSR-3 `log()` signature** (see below).

**PHP floor remains 8.1** (core SDK uses enums / `readonly` / `match`). Silverstripe 4 on PHP 7.4 or 8.0 is not supported.

If `composer require` still conflicts, check `composer why-not psr/log 3` / `composer why monolog/monolog` — another package may be forcing an impossible set; this module’s ranges alone should satisfy a stock SS4–6 tree.

#### `Talaria\Logger` and older `LoggerInterface::log()`

psr/log **1.x** declares:

```php
public function log($level, $message, array $context = []);
```

with **no** `$message` type. A child that adds `\Stringable|string $message` is incompatible under PHP’s signature rules and fatals with:

`Declaration … must be compatible with … LoggerInterface::log($level, $message…)`

PSR-3 always described the message as a string or `__toString()` object; it never required a PHP parameter type. So `Talaria\Logger::log()` stays:

```php
public function log($level, $message, array $context = []): void
```

with the contract in PHPDoc and `(string) $message` at runtime. That remains compatible with psr/log **1, 2, and 3** (untyped parameters are wider than the typed ones on 2/3).

CI runs PHPUnit against those three `psr/log` majors (see `.github/workflows/ci.yml`). Locally:

```bash
composer test:psr-log-matrix
```

### Browser JS (CMS + frontend)

With the same env vars, the module automatically loads the published npm package [`@newtalaria/browser`](https://www.npmjs.com/package/@newtalaria/browser) (via jsDelivr ESM) on:

- **CMS admin** (`LeftAndMain`) — tag `runtime=silverstripe-cms`, **`inlineStylesheet: true`**, failed HTTP **400–599** promoted to events
- **Public pages** (`ContentController`) — tag `runtime=silverstripe-frontend`, `inlineStylesheet: false`, failed HTTP **500–599** only (SDK default)

Default integrations capture `window` errors and unhandled promise rejections. Session replay is **off** by default (`browserReplays*SampleRate: 0`). Failed XHR/fetch responses matching the status ranges above are also sent as events (e.g. GridField 404s in CMS).

Override in app `_config`:

```yaml
---
Name: app-talaria
After:
  - '#talaria-logging'
  - '#talaria-logging-browser'
---
Talaria\SilverStripe\Config:
  minLevel: warning
  maxBatchSize: 50
  flushIntervalMs: 2000
  enableBrowserCms: true
  enableBrowserFrontend: true
  # Pin the npm package version loaded from jsDelivr
  browserSdkVersion: '0.1.12'
  browserReplaysSessionSampleRate: 0
  browserReplaysOnErrorSampleRate: 1.0
  # Optional: force inlineStylesheet on/off for both CMS and frontend
  # browserInlineStylesheet: true
  # Optional: disable HTTP failure → event promotion
  # browserCaptureFailedRequests: false
  # Optional: override status ranges (list of ints or [min, max] pairs)
  # browserFailedRequestStatusCodes:
  #   - [400, 599]
```

If your public pages do **not** use `ContentController`, apply the same extension:

```yaml
PageController:
  extensions:
    - Talaria\SilverStripe\FrontendExtension
```

After config changes:

```bash
vendor/bin/sake dev/build flush=1
```

#### Publish / upgrade `@newtalaria/browser`

Silverstripe loads the SDK from npm (jsDelivr), not from a local monorepo path.

1. In `new_talaria_js/packages/browser`: bump version, `npm run build`, `npm publish`
2. Set `browserSdkVersion` to that version (module default is `0.1.12`)
3. Flush + hard-refresh `/admin`
4. Confirm `window.__TALARIA_CONFIG__.inlineStylesheet === true` on CMS pages
5. Confirm `failedRequestStatusCodes` is `[[400,599]]` on CMS (and `[[500,599]]` on frontend)
6. Trigger an error with replay sampling on; playback should include admin styling for same-origin sheets
7. Trigger a CMS GridField 404 / 5xx XHR and confirm a Talaria event like `HTTP 404: GET …`

## Batching

```
capture* → EventQueue → POST /events/ingestBatch
```

- Never sends one HTTP request per log via `events/ingest`
- Leftover events at shutdown are still sent as a batch (size 1+)
- Under PHP-FPM there is no background timer; age is checked on each enqueue, plus shutdown flush

## Wire contract

```
POST {dsn}/events/ingestBatch
X-API-Key: tal_live_…
```

```json
{
  "input": {
    "__className__": "IngestEventBatchInput",
    "events": [
      {
        "__className__": "IngestEventInput",
        "message": "…",
        "environment": "production",
        "level": "error",
        "eventType": "error"
      }
    ]
  }
}
```

Browser JS uses the published [`@newtalaria/browser`](https://www.npmjs.com/package/@newtalaria/browser) package (`POST {dsn}/events/ingest`) loaded from jsDelivr at the configured `browserSdkVersion`.

## Development

```bash
composer install
composer test
composer test:psr-log-matrix   # psr/log 1.x + 2.x + 3.x
```

## License

MIT
