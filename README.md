# newtalaria/logging

PHP SDK for [Talaria](https://newtalaria.com) — logging and error tracking. Framework-agnostic core with a Silverstripe Monolog adapter.

Events are **queued in memory** and sent with `POST /events/ingestBatch` when the buffer hits a size limit, exceeds a max age, or the request shuts down. Fingerprinting stays on the server.

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

The module pushes a Monolog `TalariaLogHandler` onto the default logger. Existing `Injector::inst()->get(LoggerInterface::class)->error(...)` calls are forwarded into the Talaria batch queue.

Override defaults in your app `_config`:

```yaml
Talaria\SilverStripe\Config:
  minLevel: warning
  maxBatchSize: 50
  flushIntervalMs: 2000
```

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

## Development

```bash
composer install
composer test
```

## License

MIT
