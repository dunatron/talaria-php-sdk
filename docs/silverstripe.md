# Silverstripe guide

Set up Talaria on **Silverstripe 4.13+ / 5 / 6** (PHP **8.1+**) with `newtalaria/logging`. One Composer package installs the core PHP SDK and the Silverstripe module (Monolog handler, Injector wiring, optional browser inject).

You can instrument your app in two ways. They share the same credentials and can run together.

| | Approach A — Monolog / `LoggerInterface` | Approach B — Talaria Logger |
| --- | --- | --- |
| **Best for** | CMS + modules; vendor-agnostic call sites | Product/feature instrumentation |
| **API** | PSR-3 only | Scoped tags, `child`, `withMinLevel`, `isLevelEnabled`, `warn`, `captureException` |
| **Coupling** | Minimal — type-hint `LoggerInterface` | Explicit Talaria types where you choose |
| **Parity** | Standard PHP logging | Same model as [`@newtalaria/browser`](https://www.npmjs.com/package/@newtalaria/browser) |

**Hybrid (typical in practice):** keep Approach A so CMS and third-party modules still flow into Talaria; use Approach B in your checkout, billing, and other product services.

---

## Shared setup

### 1. Install

```bash
composer require newtalaria/logging
```

### 2. Environment variables

Create a client key under **Project settings → Client keys** (`tal_live_…`), then set:

```bash
TALARIA_DSN="https://api.newtalaria.com"
TALARIA_API_KEY="tal_live_…"
TALARIA_ENVIRONMENT="production"
TALARIA_RELEASE="1.2.3"
TALARIA_COMMIT_SHA="…"   # optional; enables GitHub source context on stack frames

# Optional APM (off by default)
# TALARIA_ENABLE_TRACING="true"
# TALARIA_TRACES_SAMPLE_RATE="0.1"

# Optional: browser inject only when the PHP DSN is not reachable from the browser
# (e.g. Docker-internal HTTP behind an HTTPS site).
# TALARIA_BROWSER_DSN="https://api.newtalaria.com"
```

Missing DSN or key disables the client safely so install / flush will not crash.

### 3. Flush config

```bash
vendor/bin/sake dev/build flush=1
```

### 4. Optional YAML overrides

`app/_config/talaria.yml` (or equivalent):

```yaml
---
Name: app-talaria
After:
  - '#talaria-logging'
  - '#talaria-logging-browser'
---
Talaria\SilverStripe\Config:
  minLevel: warning
  enforceDefaultLevel: false
  # Optional Approach B presets:
  # loggers:
  #   businessDirectory:
  #     minLevel: info
  #     tags:
  #       area: businessDirectory
  service: 'my-site'
  tags:
    team: 'platform'
  maxBatchSize: 50
  flushIntervalMs: 2000
  enableTracing: false
  # tracesSampleRate: 0.1
  enableBrowserCms: true
  enableBrowserFrontend: true
  browserSdkVersion: '0.1.21'
  browserReplaysSessionSampleRate: 0
  browserReplaysOnErrorSampleRate: 1.0
```

YAML `minLevel` (default **`warning`**) is the **global default/root**: the Monolog handler threshold and the shared `TalariaClient` default. Monolog stays at that global default only. Scoped `Talaria\Logger` overrides (and named `loggers`) may be more verbose unless `enforceDefaultLevel: true`. See [logging-levels.md](logging-levels.md). Browser inject receives the same `minLevel` / `enforceDefaultLevel` / `loggers`.

### Tracing (APM)

Tracing is **off** until `enableTracing: true` or `TALARIA_ENABLE_TRACING=true` (or `tracesSampleRate` / `TALARIA_TRACES_SAMPLE_RATE` > 0). When enabled without an explicit rate, successful transactions sample at **10%**; **error** transactions are always sent. The same project API key is used for `POST /spans/ingestBatch` (`spansWrite` is included on default app keys).

With tracing on, the module registers:

| Signal | Instrumentation |
| --- | --- |
| Incoming HTTP | `Talaria\SilverStripe\HttpMiddleware` — SERVER span per request (`http.request.method`, `http.route`, `http.response.status_code`). Continues W3C `traceparent` when present |
| MySQL | `TracingMySQLDatabase` wraps `query` / `preparedQuery` as CLIENT spans (`db.system.name=mysql`, `db.operation.name`, sanitized `db.query.text`). Repeated identical queries are each sent (N+1 stays visible) |
| Outbound HTTP | Injector `GuzzleHttp\Client` gets Talaria Guzzle middleware (client span + `traceparent`). The SDK’s own ingest Guzzle client is **not** wrapped |
| Queued jobs | When `symbiote/silverstripe-queuedjobs` is installed: PRODUCER on enqueue, CONSUMER on run |
| Redis | Not in Silverstripe core. Wrap Predis or phpredis with `Talaria\Tracing\RedisInstrumentation::wrap($redis, $client)` |

Framework-agnostic PHP (no Silverstripe): `TracingPdo` / `TracingMysqli` for SQL, `Psr15Middleware` or `IncomingHttp::startTransaction()` for incoming HTTP, `Talaria::getTraceparent()` to continue a browser trace.

Manual breadcrumbs and child spans:

```php
use SilverStripe\Core\Injector\Injector;
use Talaria\TalariaClient;

$client = Injector::inst()->get(TalariaClient::class);
$client->addBreadcrumb([
    'type' => 'default',
    'category' => 'checkout',
    'message' => 'coupon applied',
    'level' => 'info',
]);
```

Flush again after YAML changes: `vendor/bin/sake dev/build flush=1`.

---

## Approach A — Monolog + Injector (recommended default)

### When to use it

- You want controllers and services to stay on `Psr\Log\LoggerInterface`
- CMS and modules already log through Silverstripe’s logger
- You want to swap or remove Talaria later without rewriting call sites

### How it works

The module registers `talariaLogHandler` on the default Injector logger. Records at or above `minLevel` are forwarded into the Talaria batch queue. Your code never has to mention Talaria.

### Usage in practice

**Constructor injection** (preferred for Injector-managed classes):

```php
use Psr\Log\LoggerInterface;

final class CheckoutService
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }

    public function pay(string $orderId): void
    {
        $this->logger->warning('Payment method missing', [
            'tags' => ['feature' => 'checkout', 'operation' => 'pay'],
            'order_id' => $orderId,
        ]);

        try {
            $this->charge($orderId);
        } catch (\Throwable $e) {
            // exception key → captureException with a real stack
            $this->logger->error('Checkout failed', [
                'exception' => $e,
                'tags' => ['feature' => 'checkout', 'component' => 'stripe'],
                'order_id' => $orderId,
            ]);
            throw $e;
        }
    }
}
```

**Procedural / non-Injector entry points:**

```php
use Psr\Log\LoggerInterface;
use SilverStripe\Core\Injector\Injector;

/** @var LoggerInterface $logger */
$logger = Injector::inst()->get(LoggerInterface::class);

$logger->warning('Payment retry scheduled', [
    'tags' => ['feature' => 'checkout'],
    'order_id' => '123',
]);
```

### Context conventions (Approach A)

| Context key | Use |
| --- | --- |
| `tags` | Low-cardinality filters (`feature`, `operation`, `component`) |
| Other scalar keys | Treated as `extra` (e.g. `order_id`) |
| `exception` | `\Throwable` → structured `captureException` |

Do **not** type-hint `Talaria\TalariaClient` in Approach A unless you intentionally need the client API.

---

## Approach B — Talaria Logger (world-class API)

### When to use it

- You want scoped loggers, `child` / `withMinLevel`, `isLevelEnabled`, `warn`, and first-class `captureException`
- You want the same logging model as the [browser SDK](https://www.npmjs.com/package/@newtalaria/browser)
- You are instrumenting product flows (checkout, billing, onboarding) with consistent tags

### Setup on Silverstripe

The module already builds a shared `TalariaClient` via Injector. **Wrap that client** — do **not** call `Talaria::init()` again (a second init is ignored and logs a warning).

```php
use SilverStripe\Core\Injector\Injector;
use Talaria\Logger;
use Talaria\TalariaClient;

$logger = new Logger(Injector::inst()->get(TalariaClient::class), [
    'tags' => [
        'feature' => 'checkout',
        'operation' => 'pay',
    ],
]);
```

Or inject `TalariaClient` into a service and build a scoped logger there:

```php
use Talaria\Logger;
use Talaria\TalariaClient;

final class CheckoutService
{
    private Logger $logger;

    public function __construct(TalariaClient $client)
    {
        $this->logger = new Logger($client, [
            'tags' => ['feature' => 'checkout'],
        ]);
    }
}
```

`Talaria\Logger` still implements `Psr\Log\LoggerInterface`, so you get PSR-3 methods plus Talaria helpers.

### Best practices

#### Feature-scoped logger per flow

```php
private function checkoutLogger(string $operation): Logger
{
    return new Logger(
        Injector::inst()->get(TalariaClient::class),
        [
            'tags' => [
                'feature' => 'checkout',
                'operation' => $operation,
            ],
        ],
    );
}

$logger = $this->checkoutLogger('review');
$logger->warn('Address validation failed', [
    'extra' => ['field' => 'postcode'],
]);
```

#### Tags vs `extra`

| Use | For | Examples |
| --- | --- | --- |
| **tags** | Dimensions you filter/group on | `feature`, `operation`, `component`, `service` |
| **extra** | High-cardinality diagnostics | `cart_id`, payloads, counts |

```php
$logger->error('Charge declined', [
    'tags' => [
        'feature' => 'checkout',
        'operation' => 'charge',
        'component' => 'stripe',
    ],
    'extra' => [
        'cart_id' => 'cart_01H…',
        'decline_code' => 'insufficient_funds',
    ],
]);
```

Do not put `environment` / `release` in tags (use env / YAML). Do not put user ids, emails, or order ids in tags — use `userId` / `extra`.

#### Child logger that only sends errors

```php
$analytics = $logger->child([
    'tags' => ['component' => 'analytics'],
    'minLevel' => 'error', // quieter than YAML default warning
]);

$analytics->info('page_view'); // no-op when floor is error
$analytics->captureException($e);

// More verbose than default (enforceDefaultLevel must be false — the default):
$directory = Talaria::logger([
    'minLevel' => 'info',
    'tags' => ['area' => 'businessDirectory'],
]);
// or: Talaria::logger('businessDirectory') with YAML loggers.businessDirectory
```

Scoped `minLevel` may raise or lower relative to the YAML default unless `enforceDefaultLevel: true`.

#### Guard expensive context

```php
if ($logger->isLevelEnabled('info')) {
    $logger->info('Cart snapshot', [
        'extra' => ['lines' => $this->expensiveCartDump()],
    ]);
}
```

#### Exceptions vs message-level errors

```php
try {
    $this->charge($orderId);
} catch (\Throwable $e) {
    // Prefer captureException for real stacks
    $logger->captureException($e, [
        'tags' => ['component' => 'stripe'],
        'extra' => ['order_id' => $orderId],
    ]);
    throw $e;
}

// Or PSR-3 with exception key (same underlying path):
$logger->error('Checkout failed', [
    'exception' => $e,
    'tags' => ['component' => 'stripe'],
    'order_id' => $orderId,
]);
```

#### PSR-3 placeholders

```php
$logger->warning('Order {order_id} delayed', [
    'order_id' => '42',
    'tags' => ['feature' => 'fulfilments'],
]);
// message becomes: Order 42 delayed
```

#### Request enrichment on the Injector client

Stock Silverstripe wiring does not expose `beforeSend` in YAML. For per-request tags, use `addProcessor` on the shared client (e.g. in an extension or `_config.php` after boot):

```php
use SilverStripe\Core\Injector\Injector;
use Talaria\TalariaClient;

$client = Injector::inst()->get(TalariaClient::class);
$client->addProcessor(static function (array $bag): array {
    return [
        'tags' => [
            'host' => $_SERVER['HTTP_HOST'] ?? 'cli',
        ],
    ];
});
```

For apps that construct their own client (plain PHP), use init `beforeSend` to redact or drop events — see the [main README](../README.md).

---

## Choosing an approach

| Prefer A (Monolog) | Prefer B (Talaria Logger) |
| --- | --- |
| Framework-default logging everywhere | Explicit product/feature instrumentation |
| Modules already log via `LoggerInterface` | Need `child` / scoped tags / `isLevelEnabled` |
| Minimal Talaria coupling | Want API parity with the browser SDK |

**Hybrid:** leave the Monolog handler enabled; in your app services use Approach B for flows you triage by `feature` / `operation`.

---

## Optional browser inject

With the same env vars, the module can load [`@newtalaria/browser`](https://www.npmjs.com/package/@newtalaria/browser) from jsDelivr into:

- CMS admin (`LeftAndMain`) when `enableBrowserCms` is true  
- Public pages (`ContentController`) when `enableBrowserFrontend` is true  

Pin the npm version with `browserSdkVersion` (default **`0.1.21`**). Replay session sampling defaults to off; on-error clips can be enabled via YAML.

Details: [`client/README.md`](../client/README.md). If public pages do not use `ContentController`, apply `Talaria\SilverStripe\FrontendExtension` on your page controller.

---

## Troubleshooting

| Symptom | What to check |
| --- | --- |
| `info` / `debug` never appear | YAML default is `warning` — use Approach B scoped/named logger, or lower `minLevel` |
| “init() called more than once” | Do not call `Talaria::init()` when using the Injector `TalariaClient` |
| 401 / 403 | `TALARIA_API_KEY` belongs to the project |
| No events in the dashboard | Flush config; confirm `TALARIA_ENVIRONMENT` matches the dashboard filter; call flush in long CLI scripts |
| No traces / waterfalls | Tracing is off by default — set `TALARIA_ENABLE_TRACING=true` or YAML `enableTracing: true`, then `sake dev/build flush=1`. Errors are always sampled; successful requests follow `tracesSampleRate` (default 0.1) |
| Browser SDK missing on frontend | `enableBrowserFrontend`, `ContentController` / `FrontendExtension`, and `TALARIA_BROWSER_DSN` if needed |

More: [PHP SDK README](../README.md) · [www.newtalaria.com/docs/sdk/silverstripe](https://www.newtalaria.com/docs/sdk/silverstripe)
