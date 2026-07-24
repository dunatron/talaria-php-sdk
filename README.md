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

The module pushes a Monolog `TalariaLogHandler` onto the default logger. Existing `Injector::inst()->get(LoggerInterface::class)->error(...)` calls are forwarded into the Talaria batch queue.

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
```

## License

MIT
